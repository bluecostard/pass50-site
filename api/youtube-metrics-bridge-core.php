<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-schema-core.php';
require_once __DIR__.'/youtube-analytics-core.php';

const P50_YOUTUBE_METRICS_BRIDGE_VERSION='1.0.0';

function p50ym_column_exists(PDO $pdo,string $column): bool {
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_youtube_oauth_connections' AND COLUMN_NAME=?");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn()>0;
}

function p50ym_index_exists(PDO $pdo,string $index): bool {
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_youtube_oauth_connections' AND INDEX_NAME=?");
    $stmt->execute([$index]);
    return (int)$stmt->fetchColumn()>0;
}

function p50ym_schema_ready(PDO $pdo): bool {
    return p50_metrics_table_exists($pdo,'p50_youtube_oauth_connections')
        && p50ym_column_exists($pdo,'profile_id')
        && p50ym_column_exists($pdo,'mapped_at')
        && p50ym_column_exists($pdo,'mapped_by');
}

function p50ym_ensure_schema(?PDO $pdo=null): void {
    $pdo=$pdo??db();
    p50yo_ensure_schema();
    if(!p50ym_column_exists($pdo,'profile_id'))$pdo->exec("ALTER TABLE p50_youtube_oauth_connections ADD COLUMN profile_id VARCHAR(100) NULL AFTER channel_thumbnail_url");
    if(!p50ym_column_exists($pdo,'mapped_at'))$pdo->exec("ALTER TABLE p50_youtube_oauth_connections ADD COLUMN mapped_at DATETIME NULL AFTER last_refreshed_at");
    if(!p50ym_column_exists($pdo,'mapped_by'))$pdo->exec("ALTER TABLE p50_youtube_oauth_connections ADD COLUMN mapped_by CHAR(36) NULL AFTER mapped_at");
    if(!p50ym_index_exists($pdo,'uq_p50_youtube_oauth_profile'))$pdo->exec("CREATE UNIQUE INDEX uq_p50_youtube_oauth_profile ON p50_youtube_oauth_connections(profile_id)");
}

function p50ym_connection_for_profile(PDO $pdo,string $profileId): ?array {
    $profileId=trim($profileId);
    if($profileId===''||!p50ym_schema_ready($pdo))return null;
    $stmt=$pdo->prepare("SELECT user_id,channel_id,channel_title,channel_custom_url,channel_thumbnail_url,status,access_expires_at,last_refreshed_at,updated_at FROM p50_youtube_oauth_connections WHERE profile_id=? AND status='active' LIMIT 1");
    $stmt->execute([$profileId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function p50ym_public_access(string $profileId=''): array {
    try{
        $pdo=db();
        if(!p50ym_schema_ready($pdo))return ['configured'=>false,'authorized'=>false,'mode'=>'mapping_required','authorizationRequired'=>true];
        if(trim($profileId)===''){
            $mapped=(int)$pdo->query("SELECT COUNT(*) FROM p50_youtube_oauth_connections WHERE profile_id IS NOT NULL AND status='active'")->fetchColumn();
            return ['configured'=>$mapped>0,'authorized'=>$mapped>0,'mode'=>$mapped>0?'authorized_oauth':'mapping_required','authorizationRequired'=>$mapped===0];
        }
        $connection=p50ym_connection_for_profile($pdo,$profileId);
        return ['configured'=>$connection!==null,'authorized'=>$connection!==null,'mode'=>$connection?'authorized_oauth':'mapping_required','authorizationRequired'=>$connection===null];
    }catch(Throwable){
        return ['configured'=>false,'authorized'=>false,'mode'=>'mapping_required','authorizationRequired'=>true];
    }
}

function p50ym_safe_connections(PDO $pdo): array {
    if(!p50_metrics_table_exists($pdo,'p50_youtube_oauth_connections'))return ['schemaReady'=>false,'connections'=>[],'summary'=>['total'=>0,'active'=>0,'mapped'=>0,'unmapped'=>0,'reauthorizationRequired'=>0]];
    $ready=p50ym_schema_ready($pdo);
    $profileSelect=$ready?'c.profile_id':'NULL profile_id';
    $join=$ready&&p50_metrics_table_exists($pdo,'p50_profile_registry')?'LEFT JOIN p50_profile_registry r ON r.profile_id=c.profile_id':'';
    $profileName=$join!==''?'r.public_name':'NULL';
    $analytics=p50_metrics_table_exists($pdo,'p50_youtube_analytics_snapshots')
        ?"(SELECT MAX(s.fetched_at) FROM p50_youtube_analytics_snapshots s WHERE s.channel_id=c.channel_id)"
        :'NULL';
    $rows=$pdo->query("SELECT c.channel_id,c.channel_title,c.channel_custom_url,c.channel_thumbnail_url,c.status,c.access_expires_at,c.connected_at,c.last_refreshed_at,c.updated_at,$profileSelect,$profileName profile_name,$analytics last_analytics_at FROM p50_youtube_oauth_connections c $join ORDER BY c.updated_at DESC LIMIT 100")->fetchAll();
    $connections=[];$summary=['total'=>0,'active'=>0,'mapped'=>0,'unmapped'=>0,'reauthorizationRequired'=>0];
    foreach($rows as $row){
        $summary['total']++;
        $status=(string)$row['status'];
        if($status==='active')$summary['active']++;
        if($status==='reauthorization_required')$summary['reauthorizationRequired']++;
        $profileId=trim((string)($row['profile_id']??''));
        if($profileId!=='')$summary['mapped']++;else $summary['unmapped']++;
        $connections[]=[
            'channelId'=>(string)$row['channel_id'],
            'channelTitle'=>(string)$row['channel_title'],
            'channelCustomUrl'=>(string)$row['channel_custom_url'],
            'channelThumbnailUrl'=>(string)($row['channel_thumbnail_url']??''),
            'status'=>$status,
            'profileId'=>$profileId!==''?$profileId:null,
            'profileName'=>trim((string)($row['profile_name']??''))?:null,
            'accessExpiresAt'=>$row['access_expires_at']?gmdate('c',strtotime((string)$row['access_expires_at'])):null,
            'lastRefreshedAt'=>$row['last_refreshed_at']?gmdate('c',strtotime((string)$row['last_refreshed_at'])):null,
            'lastAnalyticsAt'=>$row['last_analytics_at']?gmdate('c',strtotime((string)$row['last_analytics_at'])):null,
            'updatedAt'=>$row['updated_at']?gmdate('c',strtotime((string)$row['updated_at'])):null,
        ];
    }
    return ['schemaReady'=>$ready,'connections'=>$connections,'summary'=>$summary];
}

function p50ym_map_channel(PDO $pdo,string $channelId,?string $profileId,string $actorId): array {
    p50ym_ensure_schema($pdo);
    $channelId=trim($channelId);$profileId=trim((string)$profileId);
    if($channelId===''||!preg_match('/^UC[A-Za-z0-9_-]{10,190}$/',$channelId))throw new InvalidArgumentException('Identifiant de chaîne YouTube invalide.');
    $stmt=$pdo->prepare('SELECT channel_id FROM p50_youtube_oauth_connections WHERE channel_id=? LIMIT 1');$stmt->execute([$channelId]);
    if(!$stmt->fetchColumn())throw new InvalidArgumentException('Chaîne YouTube connectée introuvable.');
    $profileName=null;
    if($profileId!==''){
        if(!preg_match('/^[A-Za-z0-9._:-]{1,120}$/',$profileId))throw new InvalidArgumentException('Identifiant de fiche PASS50 invalide.');
        $stmt=$pdo->prepare('SELECT public_name FROM p50_profile_registry WHERE profile_id=? AND alive=1 LIMIT 1');$stmt->execute([$profileId]);$profileName=$stmt->fetchColumn();
        if($profileName===false)throw new InvalidArgumentException('Fiche PASS50 active introuvable.');
    }
    $pdo->beginTransaction();
    try{
        if($profileId!==''){
            $clear=$pdo->prepare('UPDATE p50_youtube_oauth_connections SET profile_id=NULL,mapped_at=NULL,mapped_by=NULL WHERE profile_id=? AND channel_id<>?');
            $clear->execute([$profileId,$channelId]);
        }
        $update=$pdo->prepare('UPDATE p50_youtube_oauth_connections SET profile_id=?,mapped_at=IF(? IS NULL,NULL,UTC_TIMESTAMP()),mapped_by=IF(? IS NULL,NULL,?) WHERE channel_id=?');
        $value=$profileId!==''?$profileId:null;
        $update->execute([$value,$value,$value,$actorId,$channelId]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    return ['channelId'=>$channelId,'profileId'=>$profileId!==''?$profileId:null,'profileName'=>$profileName!==false?$profileName:null];
}

function p50ym_api_error(array $response,string $endpoint,array &$result): never {
    $status=(int)($response['status']??0);
    if($status===429)$result['rateLimited']=true;
    if(in_array($status,[401,403],true))throw new RuntimeException('YouTube OAuth authorization_required');
    throw new RuntimeException('YouTube OAuth '.$endpoint.' http_'.$status);
}

function p50ym_collect(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $connection=p50ym_connection_for_profile($pdo,(string)$official['profile_id']);
    if(!$connection)throw new RuntimeException('Association YouTube OAuth introuvable.');
    $token=p50yo_refresh_access_token((string)$connection['user_id']);
    $headers=['Authorization: Bearer '.$token,'Accept: application/json'];
    $channelResponse=p50_mc_request($fetch,'https://www.googleapis.com/youtube/v3/channels?'.http_build_query(['part'=>'snippet,statistics,contentDetails','mine'=>'true']),$headers,$result);
    if((int)$channelResponse['status']<200||(int)$channelResponse['status']>=300)p50ym_api_error($channelResponse,'channels.list',$result);
    $channel=p50_mc_json($channelResponse)['items'][0]??null;
    if(!is_array($channel))throw new RuntimeException('Chaîne YouTube OAuth introuvable.');
    $channelId=(string)($channel['id']??'');
    if($channelId===''||$channelId!==(string)$connection['channel_id'])throw new RuntimeException('La chaîne OAuth ne correspond pas à l’association PASS50.');
    $stats=(array)($channel['statistics']??[]);$snippet=(array)($channel['snippet']??[]);
    $prov=p50_mc_provenance('YouTube','youtube_oauth_api','channels.list',$official,gmdate('c'),(int)$channelResponse['status'],$runUuid,'authorized_oauth');
    $account=p50_metrics_upsert_account($pdo,['profileId'=>$official['profile_id'],'platform'=>'YouTube','platformAccountId'=>$channelId,'handle'=>ltrim((string)($connection['channel_custom_url']??''),'@'),'canonicalUrl'=>$official['normalized_url'],'confidence'=>max(98,(int)$official['confidence']),'sourceType'=>'youtube_oauth_api','observedAt'=>$observedAt,'metadata'=>['channelTitle'=>(string)($snippet['title']??$connection['channel_title']??'')],'provenance'=>$prov]);
    $result['accountFound']=true;
    $followers=!empty($stats['hiddenSubscriberCount'])?null:p50_mc_int($stats,'subscriberCount');
    [$future,$invalid]=p50_mc_future_metrics(['totalViews'=>p50_mc_int($stats,'viewCount'),'videoCount'=>p50_mc_int($stats,'videoCount')]);
    if($followers===null)$result['unavailableMetrics']++;
    if($followers!==null||array_filter($future,static fn($value)=>$value!==null))p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'collector'=>'youtube_oauth_v1','sourceType'=>'youtube_oauth_api','sourceReference'=>$official['normalized_url'],'observedAt'=>$observedAt,'followers'=>$followers,'metrics'=>$future,'qualityStatus'=>$invalid?'quarantined':'usable','confidence'=>99,'runUuid'=>$runUuid,'rawPayloadHash'=>hash('sha256',(string)$channelResponse['body']),'provenance'=>$prov]);

    $uploads=(string)($channel['contentDetails']['relatedPlaylists']['uploads']??'');
    if($uploads!==''){
        $playlist=p50_mc_request($fetch,'https://www.googleapis.com/youtube/v3/playlistItems?'.http_build_query(['part'=>'snippet,contentDetails','playlistId'=>$uploads,'maxResults'=>max(1,min(10,$limit))]),$headers,$result);
        if((int)$playlist['status']>=200&&(int)$playlist['status']<300){
            $ids=[];foreach((array)(p50_mc_json($playlist)['items']??[]) as $item){$id=(string)($item['contentDetails']['videoId']??'');if($id!=='')$ids[]=$id;}
            if($ids){
                $videos=p50_mc_request($fetch,'https://www.googleapis.com/youtube/v3/videos?'.http_build_query(['part'=>'snippet,statistics,status,contentDetails,liveStreamingDetails','id'=>implode(',',$ids)]),$headers,$result);
                if((int)$videos['status']>=200&&(int)$videos['status']<300){
                    foreach((array)(p50_mc_json($videos)['items']??[]) as $video){
                        $id=(string)($video['id']??'');if($id===''||($video['status']['privacyStatus']??'public')!=='public')continue;
                        [$contentType,$youtubeFormat,$shortCandidate]=p50_mc_youtube_content_type($video);
                        $url=$contentType==='short'?'https://www.youtube.com/shorts/'.$id:'https://www.youtube.com/watch?v='.$id;
                        $vp=p50_mc_provenance('YouTube','youtube_oauth_api','videos.list',$official,gmdate('c'),(int)$videos['status'],$runUuid,'authorized_oauth');
                        $content=p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'platformContentId'=>$id,'contentType'=>$contentType,'canonicalUrl'=>$url,'title'=>(string)($video['snippet']['title']??''),'publishedAt'=>$video['snippet']['publishedAt']??null,'confidence'=>99,'sourceType'=>'youtube_oauth_api','observedAt'=>$observedAt,'metadata'=>['youtubeFormat'=>$youtubeFormat,'shortCandidate'=>$shortCandidate],'provenance'=>$vp]);
                        $result['contentsFound']++;$vs=(array)($video['statistics']??[]);$views=p50_mc_int($vs,'viewCount');$likes=p50_mc_int($vs,'likeCount');$comments=p50_mc_int($vs,'commentCount');
                        if($views===null&&$likes===null&&$comments===null){$result['unavailableMetrics']+=3;continue;}
                        p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'youtube_oauth_v1','sourceType'=>'youtube_oauth_api','sourceReference'=>$url,'observedAt'=>$observedAt,'views'=>$views,'likes'=>$likes,'comments'=>$comments,'confidence'=>99,'runUuid'=>$runUuid,'rawPayloadHash'=>hash('sha256',p50_metrics_json($video)),'provenance'=>$vp]);
                    }
                }else{$result['status']='partial';$result['errors'][]='YouTube OAuth videos.list http_'.(int)$videos['status'];}
            }
        }else{$result['status']='partial';$result['errors'][]='YouTube OAuth playlistItems.list http_'.(int)$playlist['status'];}
    }

    try{
        $analytics=p50ya_fetch_summary((string)$connection['user_id'],28);
        if(!empty($analytics['hasData'])){
            $metrics=(array)$analytics['metrics'];$period=(array)$analytics['period'];
            $ap=p50_mc_provenance('YouTube','youtube_analytics_oauth_28d','reports.query',$official,(string)$analytics['fetchedAt'],200,$runUuid,'authorized_oauth');
            p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'collector'=>'youtube_oauth_analytics_v1','sourceType'=>'youtube_analytics_oauth_28d','sourceReference'=>$official['normalized_url'],'observedAt'=>(string)$analytics['fetchedAt'],'metrics'=>['periodDays'=>(int)($period['days']??28),'periodStartDate'=>(string)($period['startDate']??''),'periodEndDate'=>(string)($period['endDate']??''),'periodViews'=>$metrics['views']??null,'estimatedMinutesWatched'=>$metrics['estimatedMinutesWatched']??null,'averageViewDuration'=>$metrics['averageViewDuration']??null,'averageViewPercentage'=>$metrics['averageViewPercentage']??null,'periodLikes'=>$metrics['likes']??null,'periodComments'=>$metrics['comments']??null,'periodShares'=>$metrics['shares']??null,'subscribersGained'=>$metrics['subscribersGained']??null,'subscribersLost'=>$metrics['subscribersLost']??null,'netSubscribers'=>$metrics['netSubscribers']??null],'confidence'=>99,'runUuid'=>$runUuid,'metadata'=>['intervalMetrics'=>true,'excludedFromCumulativeDelta'=>true],'provenance'=>$ap]);
        }
    }catch(Throwable $error){$result['status']='partial';$result['errors'][]='YouTube Analytics : '.p50_metrics_safe_error($error->getMessage());}
}
