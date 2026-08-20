<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/live-radar-v4-core.php';
require_method('GET','POST');
set_time_limit(60);

$configuredSecret=trim((string)($config['metrics']['cron_secret']??''));
$providedSecret=trim((string)($_SERVER['HTTP_X_PASS50_CRON_SECRET']??''));
$cron=$configuredSecret!==''&&strlen($configuredSecret)>=32&&$providedSecret!==''&&hash_equals($configuredSecret,$providedSecret);
if(!$cron)json_response(['ok'=>false,'error'=>'cron_unauthorized'],403);

try { db()->exec("SET time_zone = '+00:00'"); } catch (Throwable) {}
p50_live_v4_ensure_schema();

$enabledRaw=p50_de_get_setting(P50_LIVE_V4_UNKNOWN_AUDIT_ENABLED_SETTING,true);
$enabled=!in_array($enabledRaw,[false,0,'0','false','off'],true);

if($_SERVER['REQUEST_METHOD']==='GET'){
    if(!$enabled)json_response(['ok'=>true,'enabled'=>false,'unknowns'=>[],'p0'=>p50_live_v4_dynamic_p0_watch(),'seedP0'=>P50_LIVE_V4_P0_TIKTOK]);
    $state=p50_de_load_public_state();
    $unknowns=[];
    foreach(p50_live_v4_sources($state) as $source){
        $platform=(string)($source['platform']??'');
        if(!in_array($platform,['TikTok','YouTube','Facebook'],true))continue;
        $last=strtolower(trim((string)($source['last_state']??'never_checked')));
        if(!in_array($last,['unknown','never_checked',''],true))continue;
        $identity=p50_live_v4_identity($platform,(string)$source['url']);
        $liveUrl=(string)($identity['liveUrl']??$source['url']);
        if($platform==='YouTube'){
            $ytLive=p50_live_v4_youtube_live_url((string)$source['url']);
            if($ytLive!=='')$liveUrl=$ytLive;
        }
        $unknowns[]=[
            'profileId'=>(string)$source['profile_id'],
            'name'=>(string)($source['public_name']??''),
            'platform'=>$platform,
            'url'=>(string)$source['url'],
            'handle'=>(string)($identity['handle']??''),
            'liveUrl'=>$liveUrl,
            'lastState'=>$last===''?'never_checked':$last,
            'lastCheckedAt'=>(string)($source['last_checked_at']??''),
            'alreadyP0'=>p50_live_v4_is_p0_source($source),
        ];
    }
    json_response([
        'ok'=>true,
        'enabled'=>true,
        'tool'=>'webcast.tiktok.com/webcast/room/info_by_user',
        'unknowns'=>$unknowns,
        'p0'=>p50_live_v4_dynamic_p0_watch(),
        'seedP0'=>P50_LIVE_V4_P0_TIKTOK,
    ]);
}

if(!$enabled)json_response(['ok'=>true,'enabled'=>false,'added'=>[],'published'=>0]);

$input=json_input();
$incoming=is_array($input['lives']??null)?$input['lives']:[];
$state=p50_de_load_public_state();
$sourceMap=[];
foreach(p50_live_v4_sources($state) as $source){
    $sourceMap[p50_live_v4_p0_key((string)$source['profile_id'],(string)$source['platform'])]=$source;
}

$watch=p50_live_v4_dynamic_p0_watch();
$additions=[];$published=0;$skipped=[];$stored=[];
foreach($incoming as $item){
    if(!is_array($item)||count($additions)+count($stored)>=20)break;
    $profileId=trim((string)($item['profileId']??$item['profile_id']??''));
    $platform=trim((string)($item['platform']??''));
    $key=p50_live_v4_p0_key($profileId,$platform);
    if(!isset($sourceMap[$key])){$skipped[]=['profileId'=>$profileId,'platform'=>$platform,'error'=>'unknown_source'];continue;}
    $source=$sourceMap[$key];
    $live=p50_live_v4_unknown_audit_live_payload($source,$item);
    if($live===null){$skipped[]=['profileId'=>$profileId,'platform'=>$platform,'error'=>'invalid_proof'];continue;}
    if(p50_live_v4_store_live($live)){
        $published++;
        p50_live_v4_health_update($source,['state'=>'live','confidence'=>(int)$live['confidence'],'error'=>'','responseMs'=>(int)($item['responseMs']??0),'probes'=>['unknown_audit'=>['ok'=>true,'status'=>200,'timeMs'=>(int)($item['responseMs']??0),'error'=>'']],'evidence'=>['source'=>'github_unknown_audit']]);
        $stored[]=['profileId'=>$profileId,'platform'=>$platform];
    }else{
        $skipped[]=['profileId'=>$profileId,'platform'=>$platform,'error'=>'not_publishable'];
        continue;
    }
    if(p50_live_v4_is_p0_source($source))continue;
    $identity=p50_live_v4_identity($platform,(string)$source['url']);
    $additions[]=[
        'profileId'=>$profileId,
        'platform'=>$platform,
        'handle'=>(string)($identity['handle']??''),
        'reason'=>'unknown_audit_live',
        'addedAt'=>gmdate(DATE_ATOM),
    ];
}

$merged=p50_live_v4_merge_p0_watch($watch,$additions);
$added=[];
$before=[];
foreach($watch as $row)$before[p50_live_v4_p0_key($row['profileId'],$row['platform'])]=true;
foreach($merged as $row){
    $key=p50_live_v4_p0_key($row['profileId'],$row['platform']);
    if(!isset($before[$key]))$added[]=$row;
}
if($added)p50_de_set_setting(P50_LIVE_V4_P0_WATCH_SETTING,$merged);
p50_de_set_setting('live_radar_v4_unknown_audit_last',['at'=>gmdate(DATE_ATOM),'published'=>$published,'added'=>count($added)]);

json_response([
    'ok'=>true,
    'enabled'=>true,
    'published'=>$published,
    'added'=>$added,
    'stored'=>$stored,
    'skipped'=>$skipped,
    'p0Count'=>count($merged),
]);

function p50_live_v4_unknown_audit_live_payload(array $source,array $item): ?array {
    $platform=(string)$source['platform'];
    $profileId=(string)$source['profile_id'];
    $name=trim((string)($source['public_name']??$profileId));
    $identity=p50_live_v4_identity($platform,(string)$source['url']);
    if($platform==='TikTok'){
        $roomId=trim((string)($item['roomId']??''));
        $handle=trim((string)($identity['handle']??''),'@');
        if($roomId===''||$handle===''||!preg_match('/^[1-9]\d{5,}$/',$roomId))return null;
        $title=trim((string)($item['title']??''));
        if($title===''||preg_match('/^(TikTok|Make Your Day)$/iu',$title))$title=$name;
        if($title!==''&&!preg_match('/\b(direct|live)\b/iu',$title))$title.=' est en direct';
        $startedAt=trim((string)($item['startedAt']??''));
        if($startedAt!==''&&!preg_match('/^\d{4}-\d{2}-\d{2} /',$startedAt))$startedAt='';
        return [
            'profileId'=>$profileId,'platform'=>'TikTok','title'=>$title!==''?$title:'Direct TikTok détecté',
            'url'=>$identity['liveUrl'],'thumbnail'=>trim((string)($item['thumbnail']??'')),
            'confidence'=>99,'startedAt'=>$startedAt!==''?$startedAt:null,
            'viewers'=>isset($item['viewers'])&&is_numeric($item['viewers'])?(int)$item['viewers']:null,
            'metadata'=>[
                'profileUrl'=>$identity['profileUrl'],'handle'=>'@'.$handle,'roomId'=>$roomId,
                'probeLabels'=>['api_webcast'],'proofFamilies'=>['api'=>['api_webcast'],'html'=>[]],
                'strictApiLabels'=>['api_webcast'],'freshApiLabels'=>[],'apiLiveStructureLabels'=>['api_webcast'],
                'classification'=>'live','probe'=>'unknown_audit_webcast',
            ],
        ];
    }
    if($platform==='YouTube'){
        $videoId=trim((string)($item['videoId']??''));
        if($videoId===''||!preg_match('/^[A-Za-z0-9_-]{6,}$/',$videoId))return null;
        $title=trim((string)($item['title']??''));
        if($title==='')$title=$name!==''?$name:'Direct YouTube en cours';
        $url='https://www.youtube.com/watch?v='.$videoId;
        return [
            'profileId'=>$profileId,'platform'=>'YouTube','title'=>$title,'url'=>$url,
            'thumbnail'=>trim((string)($item['thumbnail']??('https://i.ytimg.com/vi/'.rawurlencode($videoId).'/hqdefault.jpg'))),
            'confidence'=>99,'startedAt'=>null,
            'viewers'=>isset($item['viewers'])&&is_numeric($item['viewers'])?(int)$item['viewers']:null,
            'metadata'=>['channelUrl'=>(string)$source['url'],'videoId'=>$videoId,'probe'=>'unknown_audit','liveSignal'=>'isLiveNow'],
        ];
    }
    if($platform==='Facebook'){
        $url=trim((string)($item['url']??''));
        if($url===''||!p50_public_http_url($url)||!str_contains(strtolower($url),'facebook.com'))return null;
        $title=trim((string)($item['title']??''));
        if($title==='')$title=$name!==''?$name:'Direct Facebook en cours';
        return [
            'profileId'=>$profileId,'platform'=>'Facebook','title'=>$title,'url'=>$url,
            'thumbnail'=>trim((string)($item['thumbnail']??'')),
            'confidence'=>96,'startedAt'=>null,
            'viewers'=>isset($item['viewers'])&&is_numeric($item['viewers'])?(int)$item['viewers']:null,
            'metadata'=>['probe'=>'unknown_audit','broadcastId'=>trim((string)($item['videoId']??''))],
        ];
    }
    return null;
}
