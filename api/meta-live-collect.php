<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/live-radar-v4-core.php';
require __DIR__.'/meta-oauth-core.php';
require_method('POST');set_time_limit(50);p50mo_ensure_schema();p50_live_v4_ensure_schema();
$configuredSecret=trim((string)($config['metrics']['cron_secret']??''));$providedSecret=trim((string)($_SERVER['HTTP_X_PASS50_CRON_SECRET']??''));$cron=$configuredSecret!==''&&strlen($configuredSecret)>=32&&$providedSecret!==''&&hash_equals($configuredSecret,$providedSecret);
if($cron){$assets=db()->query("SELECT a.* FROM p50_meta_oauth_assets a JOIN p50_meta_oauth_connections c ON c.user_id=a.user_id WHERE a.status='active' AND c.status='active' ORDER BY a.id LIMIT 500")->fetchAll();$requestUserId=null;}
else{$user=auth_user();$requestUserId=(string)$user['id'];$connection=p50mo_connection($requestUserId);if(!$connection)json_response(['error'=>'Aucun compte Meta connecté.'],409);if((string)$connection['status']!=='active')json_response(['error'=>'La connexion Meta doit être renouvelée.'],409);$assets=p50mo_assets($requestUserId);}
function p50_meta_store_live(array $asset,array $live): void {
    $profileId=trim((string)($asset['profile_id']??''));
    if($profileId==='')return;
    $platform=(string)$asset['platform'];
    $url=(string)$live['url'];
    $key=hash('sha256',strtolower($profileId.'|'.$platform.'|'.rtrim($url,'/')));
    $title=trim((string)($live['title']??''));
    if($title==='')$title='Direct '.$platform.' en cours';
    $end=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()) WHERE profile_id=? AND platform=? AND source='meta_authorized' AND status IN ('live','unconfirmed') AND stream_key<>?");
    $end->execute([$profileId,$platform,$key]);
    $stmt=db()->prepare("INSERT INTO p50_live_streams(stream_key,profile_id,platform,title,url,thumbnail_url,status,source,confidence,viewers,started_at,last_seen_at,ended_at,metadata) VALUES(?,?,?,?,?,?,'live','meta_authorized',100,?,?,UTC_TIMESTAMP(),NULL,?) ON DUPLICATE KEY UPDATE title=VALUES(title),url=VALUES(url),thumbnail_url=VALUES(thumbnail_url),status='live',source='meta_authorized',confidence=100,viewers=VALUES(viewers),started_at=COALESCE(started_at,VALUES(started_at)),last_seen_at=UTC_TIMESTAMP(),ended_at=NULL,metadata=VALUES(metadata)");
    $stmt->execute([$key,$profileId,$platform,substr($title,0,255),$url,(string)($live['thumbnail']??''),$live['viewers']??null,$live['startedAt']??null,json_encode($live['metadata']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function p50_meta_end_asset(array $asset,string $reason): void {
    if(empty($asset['profile_id']))return;
    $metadata=json_encode(['endReason'=>$reason,'endedObservedAt'=>gmdate(DATE_ATOM)],JSON_UNESCAPED_SLASHES);
    $stmt=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()),metadata=JSON_MERGE_PATCH(COALESCE(metadata,'{}'),?) WHERE profile_id=? AND platform=? AND source='meta_authorized' AND status IN ('live','unconfirmed')");
    $stmt->execute([$metadata,(string)$asset['profile_id'],(string)$asset['platform']]);
}

/** Classifie la source officielle via Graph (live/offline) — ne touche pas aux unknown sur erreur API. */
function p50_meta_health_update(array $asset,string $state,string $error='',int $activeCount=0): void {
    $profileId=trim((string)($asset['profile_id']??''));
    if($profileId==='')return;
    if(!in_array($state,['live','offline'],true))return;
    $platform=(string)$asset['platform'];
    $url=trim((string)($asset['profile_url']??''));
    if($url==='')$url=$platform==='Instagram'?'https://www.instagram.com/':'https://www.facebook.com/';
    p50_live_v4_health_update(
        ['profile_id'=>$profileId,'platform'=>$platform,'url'=>$url],
        [
            'state'=>$state,
            'confidence'=>100,
            'responseMs'=>0,
            'error'=>$error,
            'probes'=>['meta_graph'=>['status'=>$state,'activeLives'=>$activeCount]],
            'evidence'=>['probe'=>'meta_graph','activeLives'=>$activeCount],
        ]
    );
}

$results=[];$activeTotal=0;$mappedTotal=0;$healthUpdated=0;
foreach($assets as $asset){
    $assetId=(string)$asset['asset_id'];
    $platform=(string)$asset['platform'];
    $assetUserId=(string)$asset['user_id'];
    $active=[];
    $error='';
    $successful=false;
    try{
        $token=p50mo_decrypt((string)$asset['access_token_encrypted']);
        if($token==='')throw new RuntimeException('Jeton Meta absent.');
        if($platform==='Facebook'){
            $response=p50mo_graph($assetId.'/live_videos',$token,['fields'=>'id,title,status,creation_time,permalink_url,live_views','limit'=>10]);
            if($response['status']<200||$response['status']>=300)throw p50mo_error($response,'Lecture des directs Facebook impossible');
            $successful=true;
            foreach((array)($response['json']['data']??[]) as $video){
                $status=strtoupper((string)($video['status']??''));
                if(!in_array($status,['LIVE','LIVE_NOW','ACTIVE'],true))continue;
                $url=(string)($video['permalink_url']??'');
                if($url!==''&&!str_starts_with($url,'http'))$url='https://www.facebook.com'.$url;
                if($url==='')$url='https://www.facebook.com/'.(string)($video['id']??'');
                $active[]=['url'=>$url,'title'=>(string)($video['title']??$asset['asset_name'].' est en direct'),'thumbnail'=>'','viewers'=>isset($video['live_views'])?(int)$video['live_views']:null,'startedAt'=>isset($video['creation_time'])?(new DateTimeImmutable((string)$video['creation_time']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null,'metadata'=>['metaAssetId'=>$assetId,'videoId'=>(string)($video['id']??''),'status'=>$status,'probe'=>'facebook_live_videos']];
            }
        }elseif($platform==='Instagram'){
            $response=p50mo_graph($assetId.'/media',$token,['fields'=>'id,caption,media_type,media_product_type,permalink,timestamp,thumbnail_url','limit'=>25]);
            if($response['status']<200||$response['status']>=300)throw p50mo_error($response,'Lecture des directs Instagram impossible');
            $successful=true;
            foreach((array)($response['json']['data']??[]) as $media){
                if(strtoupper((string)($media['media_product_type']??''))!=='LIVE')continue;
                $active[]=['url'=>(string)($media['permalink']??$asset['profile_url']??''),'title'=>trim((string)($media['caption']??''))?:((string)$asset['asset_name'].' est en direct'),'thumbnail'=>(string)($media['thumbnail_url']??''),'viewers'=>null,'startedAt'=>isset($media['timestamp'])?(new DateTimeImmutable((string)$media['timestamp']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null,'metadata'=>['metaAssetId'=>$assetId,'mediaId'=>(string)($media['id']??''),'mediaProductType'=>'LIVE','probe'=>'instagram_media']];
            }
        }
        if($successful){
            if(!$active)p50_meta_end_asset($asset,'meta_api_no_active_live');
            foreach($active as $live)p50_meta_store_live($asset,$live);
            if(!empty($asset['profile_id'])){
                p50_meta_health_update($asset,$active?'live':'offline',$active?'':'meta_api_no_active_live',count($active));
                $healthUpdated++;
            }
        }
        db()->prepare('UPDATE p50_meta_oauth_assets SET last_checked_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?')->execute([(int)$asset['id']]);
    }catch(Throwable $e){
        $error=$e->getMessage();
        error_log('Meta LIVE '.$platform.' '.$assetId.': '.$error);
        db()->prepare('UPDATE p50_meta_oauth_assets SET last_checked_at=UTC_TIMESTAMP(),last_error=? WHERE id=?')->execute([substr($error,0,255),(int)$asset['id']]);
        if(str_contains(strtolower($error),'token')||str_contains(strtolower($error),'session')){
            db()->prepare("UPDATE p50_meta_oauth_connections SET status='reauthorization_required',last_error=? WHERE user_id=?")->execute([substr($error,0,255),$assetUserId]);
        }
    }
    if(!empty($asset['profile_id']))$mappedTotal++;
    $activeTotal+=count($active);
    $results[]=['platform'=>$platform,'assetId'=>$assetId,'name'=>(string)$asset['asset_name'],'profileId'=>$asset['profile_id']?:null,'mapped'=>!empty($asset['profile_id']),'activeLives'=>count($active),'error'=>$error?:null];
}
json_response(['ok'=>true,'mode'=>$cron?'cron':'user','assetsChecked'=>count($results),'mappedAssets'=>$mappedTotal,'healthUpdated'=>$healthUpdated,'activeLives'=>$activeTotal,'results'=>$cron?[]:$results,'checkedAt'=>gmdate(DATE_ATOM)]);
