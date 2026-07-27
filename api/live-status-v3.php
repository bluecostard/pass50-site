<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('GET');
set_time_limit(55);

/**
 * PASS50 Radar LIVE V3
 * - balaie tous les liens officiels validés par cycles complets ;
 * - sonde plusieurs URL en parallèle pour réduire la durée d'analyse ;
 * - privilégie les profils signalés manuellement en direct ;
 * - conserve un diagnostic par source et n'arrête pas un live sur un simple blocage réseau ;
 * - prend en charge YouTube, TikTok, Instagram et Facebook.
 */

const P50_LIVE_PLATFORMS = ['TikTok','YouTube','Instagram','Facebook'];
const P50_LIVE_OFFICIAL_STATUSES = ['verified','owner_verified','manual_verified','ok','blocked_but_exists'];
const P50_LIVE_V3_TUNED = 1;

function p50_live_v3_ensure_schema(): void {
    p50_de_ensure_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS p50_live_streams (
        stream_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,
        profile_id VARCHAR(100) NOT NULL,
        platform VARCHAR(32) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        url TEXT NOT NULL,
        thumbnail_url TEXT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'live',
        source VARCHAR(32) NOT NULL DEFAULT 'automatic',
        confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
        viewers INT UNSIGNED NULL,
        started_at DATETIME NULL,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ended_at DATETIME NULL,
        metadata LONGTEXT NULL,
        INDEX idx_p50_live_active (status,platform,last_seen_at),
        INDEX idx_p50_live_profile (profile_id,platform,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS p50_live_source_health (
        profile_id VARCHAR(100) NOT NULL,
        platform VARCHAR(32) NOT NULL,
        url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
        official_url TEXT NOT NULL,
        last_state VARCHAR(24) NOT NULL DEFAULT 'never_checked',
        consecutive_offline SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        consecutive_unknown SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        last_checked_at DATETIME NULL,
        last_live_at DATETIME NULL,
        response_ms INT UNSIGNED NULL,
        last_error VARCHAR(255) NOT NULL DEFAULT '',
        metadata LONGTEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (profile_id,platform),
        INDEX idx_p50_live_health_state (last_state,last_checked_at),
        INDEX idx_p50_live_health_platform (platform,last_checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_live_v3_iso(?string $mysql): ?string {
    if (!$mysql) return null;
    try { return (new DateTimeImmutable($mysql, new DateTimeZone('UTC')))->format(DATE_ATOM); }
    catch (Throwable) { return null; }
}

function p50_live_v3_bool_query(string $key): bool {
    return isset($_GET[$key]) && in_array(strtolower((string)$_GET[$key]), ['1','true','yes','on'], true);
}

function p50_live_v3_direct_url(string $platform, string $url): bool {
    $url=trim($url);
    if($url===''||p50_platform($url)!==$platform)return false;
    $parts=parse_url($url);$path=(string)($parts['path']??'');
    if($platform==='TikTok')return (bool)preg_match('#/@[A-Za-z0-9._-]+(?:/live)?/?$#',$path);
    if($platform==='YouTube')return !preg_match('#/(results|search)(?:/|$)#i',$path)
        &&(bool)preg_match('#/(?:@[^/]+|channel/[^/]+|c/[^/]+|user/[^/]+|watch|live)(?:/|$)#i',$path);
    if($platform==='Instagram')return (bool)preg_match('#^/[A-Za-z0-9._-]+/?$#',$path)
        &&!preg_match('#^/(explore|accounts|reels?|stories|direct|developer|about|privacy|legal)(?:/|$)#i',$path);
    if($platform==='Facebook')return !preg_match('#^/(login|watch|groups|marketplace|gaming|events|reels?|share|sharer)(?:/|$)#i',$path)
        &&trim($path,'/')!=='';
    return false;
}

function p50_live_v3_identity(string $platform,string $url): array {
    $parts=parse_url(trim($url));$path=(string)($parts['path']??'');
    if($platform==='TikTok'&&preg_match('#/@([A-Za-z0-9._-]+)#',$path,$m)){
        $handle=$m[1];$profile='https://www.tiktok.com/@'.$handle;
        return ['handle'=>$handle,'profileUrl'=>$profile,'liveUrl'=>$profile.'/live'];
    }
    if($platform==='Instagram'&&preg_match('#^/([A-Za-z0-9._-]+)/?#',$path,$m)){
        $handle=$m[1];$profile='https://www.instagram.com/'.$handle.'/';
        return ['handle'=>$handle,'profileUrl'=>$profile,'liveUrl'=>$profile.'live/'];
    }
    if($platform==='Facebook'){
        $base='https://www.facebook.com/'.trim($path,'/');
        if(!empty($parts['query']))$base.='?'.$parts['query'];
        return ['handle'=>'','profileUrl'=>$base,'liveUrl'=>rtrim($base,'/').'/live/'];
    }
    return ['handle'=>'','profileUrl'=>$url,'liveUrl'=>$url];
}

function p50_live_v3_youtube_base(string $url): string {
    $parts=parse_url($url);
    if(!$parts||empty($parts['host']))return '';
    $scheme=(string)($parts['scheme']??'https');$host=(string)$parts['host'];$path=rtrim((string)($parts['path']??''),'/');
    if(str_contains(strtolower($host),'youtu.be'))return $url;
    if(preg_match('#/(watch|shorts|embed|live)(?:/|$)#i',$path)||!empty($parts['query']))return $url;
    $path=preg_replace('#/(featured|videos|shorts|streams|about|community)$#i','',$path)??$path;
    return $path===''?'':$scheme.'://'.$host.rtrim($path,'/').'/live';
}

function p50_live_v3_manual_priority_ids(array $state): array {
    $ids=[];$now=time();
    foreach((array)($state['liveStreams']??[]) as $live){
        if(!is_array($live)||($live['status']??'')!=='live'||empty($live['profileId']))continue;
        $ends=strtotime((string)($live['endsAt']??''));
        if($ends!==false&&$ends>0&&$ends<=$now)continue;
        $ids[(string)$live['profileId']]=true;
    }
    return $ids;
}

function p50_live_v3_active_auto_ids(): array {
    $out=[];
    try{
        $stmt=db()->query("SELECT profile_id,platform FROM p50_live_streams WHERE source='automatic' AND status='live'");
        foreach($stmt->fetchAll() as $row)$out[(string)$row['platform'].'|'.(string)$row['profile_id']]=true;
    }catch(Throwable){}
    return $out;
}

function p50_live_v3_health_map(): array {
    $out=[];
    try{
        $stmt=db()->query('SELECT profile_id,platform,last_state,last_checked_at,last_live_at,consecutive_offline,consecutive_unknown FROM p50_live_source_health');
        foreach($stmt->fetchAll() as $row)$out[(string)$row['platform'].'|'.(string)$row['profile_id']]=$row;
    }catch(Throwable){}
    return $out;
}

function p50_live_v3_sources(array $state): array {
    $threshold=p50_de_threshold();$seen=[];$out=[];
    try{
        $stmt=db()->prepare("SELECT r.profile_id,r.public_name,r.handle,s.platform,s.normalized_url url,s.confidence,'verified' verification_status
            FROM p50_profile_registry r
            JOIN p50_social_links s ON s.profile_id=r.profile_id
            WHERE r.alive=1 AND s.platform IN ('TikTok','YouTube','Instagram','Facebook') AND s.status='verified' AND s.confidence>=?");
        $stmt->execute([$threshold]);
        foreach($stmt->fetchAll() as $row){
            $platform=(string)$row['platform'];$id=(string)$row['profile_id'];$url=trim((string)$row['url']);$key=$platform.'|'.$id;
            if(isset($seen[$key])||!p50_live_v3_direct_url($platform,$url))continue;
            $seen[$key]=true;$out[]=$row;
        }
    }catch(Throwable){}

    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id'])||(array_key_exists('alive',$profile)&&empty($profile['alive'])))continue;
        foreach(P50_LIVE_PLATFORMS as $platform){
            $id=(string)$profile['id'];$key=$platform.'|'.$id;
            if(isset($seen[$key]))continue;
            $url=trim((string)(($profile['links']??[])[$platform]??''));
            $status=(string)(($profile['linkChecks']??[])[$platform]['status']??'');
            if(!in_array($status,P50_LIVE_OFFICIAL_STATUSES,true)||!p50_live_v3_direct_url($platform,$url))continue;
            $seen[$key]=true;
            $out[]=[
                'profile_id'=>$id,'public_name'=>(string)($profile['name']??$id),'handle'=>(string)($profile['handle']??''),
                'platform'=>$platform,'url'=>$url,'confidence'=>in_array($status,['owner_verified','manual_verified','verified'],true)?98:94,
                'verification_status'=>$status,
            ];
        }
    }

    $manual=p50_live_v3_manual_priority_ids($state);$automatic=p50_live_v3_active_auto_ids();$health=p50_live_v3_health_map();
    $platformOrder=['TikTok'=>0,'Instagram'=>1,'Facebook'=>2,'YouTube'=>3];
    foreach($out as &$source){
        $id=(string)$source['profile_id'];$platform=(string)$source['platform'];$key=$platform.'|'.$id;$status=(string)($source['verification_status']??'');
        $source['source_key']=$key;
        $source['priority']=isset($manual[$id])?0:(isset($automatic[$key])?1:(in_array($status,['owner_verified','manual_verified','verified'],true)?2:3));
        $source['last_checked_at']=(string)($health[$key]['last_checked_at']??'');
        $source['platform_order']=$platformOrder[$platform]??9;
    }
    unset($source);
    usort($out,static function(array $a,array $b): int {
        $cmp=((int)$a['priority'])<=>((int)$b['priority']);if($cmp!==0)return $cmp;
        $ad=(string)$a['last_checked_at'];$bd=(string)$b['last_checked_at'];
        if($ad!==$bd){if($ad==='')return -1;if($bd==='')return 1;return strcmp($ad,$bd);}
        $cmp=((int)$a['platform_order'])<=>((int)$b['platform_order']);
        return $cmp!==0?$cmp:strnatcasecmp((string)$a['public_name'],(string)$b['public_name']);
    });
    return $out;
}

function p50_live_v3_probe_requests(array $source): array {
    $platform=(string)$source['platform'];$url=(string)$source['url'];$identity=p50_live_v3_identity($platform,$url);
    if($platform==='YouTube'){
        $live=p50_live_v3_youtube_base($url);
        return $live!==''?['live'=>['url'=>$live,'accept'=>'text/html,*/*;q=0.7']]:[];
    }
    if($platform==='TikTok'&&$identity['handle']!==''){
        $handle=rawurlencode($identity['handle']);
        return [
            'api'=>['url'=>'https://www.tiktok.com/api-live/user/room/?aid=1988&sourceType=54&uniqueId='.$handle,'accept'=>'application/json,text/plain,*/*'],
            'api_basic'=>['url'=>'https://www.tiktok.com/api-live/user/room/?aid=1988&uniqueId='.$handle,'accept'=>'application/json,text/plain,*/*'],
            'mobile_live'=>['url'=>'https://m.tiktok.com/@'.$handle.'/live','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'live'=>['url'=>$identity['liveUrl'].'?lang=fr','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'embed'=>['url'=>'https://www.tiktok.com/embed/live/@'.$handle.'?autoplay=0&muted=1&controls=1&embed_domain=pass50.store','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'profile'=>['url'=>$identity['profileUrl'].'?lang=fr','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
        ];
    }
    if($platform==='Instagram'&&$identity['handle']!==''){
        return [
            'profile'=>['url'=>$identity['profileUrl'].'?hl=fr','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'live'=>['url'=>$identity['liveUrl'],'accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
        ];
    }
    if($platform==='Facebook'){
        return [
            'live'=>['url'=>$identity['liveUrl'],'accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'videos'=>['url'=>rtrim($identity['profileUrl'],'/').'/videos/','accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
            'mobile'=>['url'=>str_replace('www.facebook.com','m.facebook.com',$identity['profileUrl']),'accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'],
        ];
    }
    return [];
}

function p50_live_v3_parallel_fetch(array $jobs,int $timeout=6): array {
    if(!$jobs)return [];
    $multi=curl_multi_init();$handles=[];$results=[];
    if(defined('CURLMOPT_MAX_TOTAL_CONNECTIONS'))@curl_multi_setopt($multi,CURLMOPT_MAX_TOTAL_CONNECTIONS,16);
    foreach($jobs as $jobId=>$job){
        $url=(string)$job['url'];
        if(!p50_public_http_url($url)){$results[$jobId]=['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>$url,'error'=>'invalid_url','timeMs'=>0];continue;}
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
            CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>min(3,$timeout),CURLOPT_ENCODING=>'',
            CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER=>[
                'Accept: '.(string)($job['accept']??'text/html,*/*;q=0.7'),
                'Accept-Language: fr-FR,fr;q=0.9,en;q=0.7','Cache-Control: no-cache','Pragma: no-cache','DNT: 1',
                'Referer: https://www.google.com/',
            ],
            CURLOPT_HEADER=>false,
        ]);
        $handles[(int)$ch]=['handle'=>$ch,'id'=>$jobId,'url'=>$url];curl_multi_add_handle($multi,$ch);
    }
    do{$status=curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,0.35);}while($active&&$status===CURLM_OK);
    foreach($handles as $item){
        $ch=$item['handle'];$body=curl_multi_getcontent($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $results[$item['id']]=[
            'ok'=>is_string($body)&&$http>=200&&$http<400,'status'=>$http,'body'=>is_string($body)?$body:'',
            'finalUrl'=>(string)(curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)?:$item['url']),'error'=>curl_error($ch),
            'timeMs'=>(int)round(((float)curl_getinfo($ch,CURLINFO_TOTAL_TIME))*1000),
        ];
        curl_multi_remove_handle($multi,$ch);curl_close($ch);
    }
    curl_multi_close($multi);return $results;
}

function p50_live_v3_video_id(string $url,string $html=''): string {
    $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));$path=(string)($parts['path']??'');
    if(str_contains($host,'youtu.be'))return trim($path,'/');
    parse_str((string)($parts['query']??''),$query);if(!empty($query['v']))return (string)$query['v'];
    if(preg_match('#/(?:shorts|embed|live)/([A-Za-z0-9_-]{6,})#',$path,$m))return $m[1];
    foreach(['/"videoId"\s*:\s*"([A-Za-z0-9_-]{6,})"/','/youtube\.com\/watch\?v=([A-Za-z0-9_-]{6,})/'] as $pattern)if(preg_match($pattern,$html,$m))return $m[1];
    return '';
}

function p50_live_v3_unescape(string $value): string {
    return html_entity_decode(str_replace(['\\u0026','\\u003d','\\/'],['&','=','/'],$value),ENT_QUOTES|ENT_HTML5,'UTF-8');
}

function p50_live_v3_tiktok_room_id(string $body): string {
    $body=p50_live_v3_unescape($body);
    foreach([
        '/"roomId"\s*:\s*"?([1-9]\d{5,})"?/i','/"room_id"\s*:\s*"?([1-9]\d{5,})"?/i',
        '/"liveRoomId"\s*:\s*"?([1-9]\d{5,})"?/i','/"webcastRoomId"\s*:\s*"?([1-9]\d{5,})"?/i',
        '/"LiveRoom"\s*:\s*\{.{0,500}?"id"\s*:\s*"?([1-9]\d{5,})"?/is',
        '/[?&]room_id=([1-9]\d{5,})/i',
    ] as $pattern)if(preg_match($pattern,$body,$m))return (string)$m[1];
    return '';
}

function p50_live_v3_viewers(string $body): ?int {
    foreach([
        '/"concurrentViewers"\s*:\s*"?(\d+)"?/i','/"user_count"\s*:\s*"?(\d+)"?/i',
        '/"viewerCount"\s*:\s*"?(\d+)"?/i','/"liveRoomUserCount"\s*:\s*"?(\d+)"?/i',
        '/"roomUserCount"\s*:\s*"?(\d+)"?/i',
    ] as $pattern)if(preg_match($pattern,$body,$m))return (int)$m[1];
    return null;
}

function p50_live_v3_parse_youtube(array $source,array $responses): array {
    $r=$responses['live']??[];$html=(string)($r['body']??'');
    if(empty($r['ok'])||$html==='')return ['state'=>'unknown','error'=>(string)($r['error']??('http_'.($r['status']??0))),'confidence'=>0];
    $isLive=(bool)preg_match('/"isLiveNow"\s*:\s*true/i',$html)
        ||(bool)preg_match('/itemprop=["\']isLiveBroadcast["\'][^>]+content=["\']True["\']/i',$html)
        ||((bool)preg_match('/"isLiveContent"\s*:\s*true/i',$html)&&(bool)preg_match('/"playabilityStatus"\s*:\s*\{[^}]*"status"\s*:\s*"OK"/is',$html));
    if(!$isLive)return ['state'=>'offline','error'=>'','confidence'=>96];
    $base=(string)($r['finalUrl']??$source['url']);$meta=p50_page_metadata($html,$base);$videoId=p50_live_v3_video_id((string)($meta['canonical']?:$base),$html);
    $url=$videoId!==''?'https://www.youtube.com/watch?v='.$videoId:(string)($meta['canonical']?:$base);
    $title=trim((string)($meta['title']??''));$title=preg_replace('/\s*-\s*YouTube\s*$/iu','',$title)??$title;if($title==='')$title='Direct YouTube en cours';
    $started=null;if(preg_match('/"startTimestamp"\s*:\s*"([^"]+)"/',$html,$m)){try{$started=(new DateTimeImmutable(p50_live_v3_unescape($m[1])))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable){}}
    $thumb=(string)($meta['image']??'');if($thumb===''&&$videoId!=='')$thumb='https://i.ytimg.com/vi/'.rawurlencode($videoId).'/hqdefault.jpg';
    return ['state'=>'live','confidence'=>99,'live'=>[
        'profileId'=>(string)$source['profile_id'],'platform'=>'YouTube','title'=>$title,'url'=>$url,'thumbnail'=>$thumb,
        'confidence'=>99,'startedAt'=>$started,'viewers'=>p50_live_v3_viewers($html),
        'metadata'=>['channelUrl'=>(string)$source['url'],'videoId'=>$videoId,'probe'=>'channel_live'],
    ]];
}

function p50_live_v3_parse_tiktok(array $source,array $responses): array {
    $identity=p50_live_v3_identity('TikTok',(string)$source['url']);$combined='';$ok=0;$errors=[];$maxMs=0;
    foreach($responses as $label=>$r){
        if(!empty($r['ok'])){$ok++;$combined.="\n".(string)$r['body']."\n".(string)($r['finalUrl']??'');}
        else $errors[]=$label.':'.((string)($r['error']??'')?:('http_'.($r['status']??0)));
        $maxMs=max($maxMs,(int)($r['timeMs']??0));
    }
    if($ok===0)return ['state'=>'unknown','error'=>implode(';',$errors),'confidence'=>0,'responseMs'=>$maxMs];
    $roomId=p50_live_v3_tiktok_room_id($combined);
    $strong=(bool)preg_match('/"(?:isLive|isLiveStreaming|is_live)"\s*:\s*true/i',$combined)
        ||(bool)preg_match('/"(?:liveStatus|live_status|status)"\s*:\s*2(?:\D|$)/i',$combined)
        ||(bool)preg_match('/"LiveRoom"\s*:/i',$combined)
        ||(bool)preg_match('/x-tiktok-player.{0,800}(?:onPlayerReady|liveRoom|roomId)/is',$combined);
    $negative=(bool)preg_match('/not currently live|live has ended|room not found|"liveStatus"\s*:\s*4|"status"\s*:\s*4/iu',$combined);
    if($roomId===''&&!$strong)return ['state'=>$negative?'offline':'unknown','error'=>$negative?'':'tiktok_no_live_signal','confidence'=>$negative?92:0,'responseMs'=>$maxMs];
    if($roomId===''&&$strong){
        $embed=(string)($responses['embed']['body']??'');
        if($embed===''||preg_match('/blocked|not currently live|live has ended/iu',$embed))return ['state'=>'unknown','error'=>'tiktok_signal_incomplete','confidence'=>0,'responseMs'=>$maxMs];
    }
    $best='';$bestUrl=$identity['liveUrl'];
    foreach(['live','embed','profile','api'] as $label){if(!empty($responses[$label]['body'])){$best=(string)$responses[$label]['body'];$bestUrl=(string)($responses[$label]['finalUrl']??$bestUrl);break;}}
    $meta=p50_page_metadata($best,$bestUrl);$title=trim((string)($meta['title']??''));$title=preg_replace('/\s*\|\s*TikTok\s*$/iu','',$title)??$title;
    if($title===''||preg_match('/^(TikTok|Make Your Day)$/iu',$title))$title=trim((string)($source['public_name']??''));
    if($title==='')$title='Direct TikTok en cours';elseif(!preg_match('/\b(direct|live)\b/iu',$title))$title.=' est en direct';
    $confidence=$roomId!==''?98:91;
    return ['state'=>'live','confidence'=>$confidence,'responseMs'=>$maxMs,'live'=>[
        'profileId'=>(string)$source['profile_id'],'platform'=>'TikTok','title'=>$title,'url'=>$identity['liveUrl'],
        'thumbnail'=>(string)($meta['image']??''),'confidence'=>$confidence,'startedAt'=>null,'viewers'=>p50_live_v3_viewers($combined),
        'metadata'=>['profileUrl'=>$identity['profileUrl'],'handle'=>'@'.$identity['handle'],'roomId'=>$roomId,'probe'=>'multi_probe'],
    ]];
}

function p50_live_v3_parse_instagram(array $source,array $responses): array {
    $identity=p50_live_v3_identity('Instagram',(string)$source['url']);$combined='';$ok=0;$errors=[];$maxMs=0;
    foreach($responses as $label=>$r){if(!empty($r['ok'])){$ok++;$combined.="\n".(string)$r['body'];}else $errors[]=$label.':http_'.($r['status']??0);$maxMs=max($maxMs,(int)($r['timeMs']??0));}
    if($ok===0)return ['state'=>'unknown','error'=>implode(';',$errors),'confidence'=>0,'responseMs'=>$maxMs];
    $live=(bool)preg_match('/"(?:is_live_broadcast|isLiveBroadcast|is_live|isLive)"\s*:\s*true/i',$combined)
        ||(bool)preg_match('/"broadcast_status"\s*:\s*"(?:active|live)"/i',$combined);
    if(!$live)return ['state'=>'unknown','error'=>'instagram_no_public_live_signal','confidence'=>0,'responseMs'=>$maxMs];
    $meta=p50_page_metadata($combined,$identity['profileUrl']);$title=trim((string)($source['public_name']??'Instagram')) . ' est en direct';
    return ['state'=>'live','confidence'=>92,'responseMs'=>$maxMs,'live'=>[
        'profileId'=>(string)$source['profile_id'],'platform'=>'Instagram','title'=>$title,'url'=>$identity['profileUrl'],
        'thumbnail'=>(string)($meta['image']??''),'confidence'=>92,'startedAt'=>null,'viewers'=>p50_live_v3_viewers($combined),
        'metadata'=>['profileUrl'=>$identity['profileUrl'],'handle'=>'@'.$identity['handle'],'probe'=>'public_profile'],
    ]];
}

function p50_live_v3_parse_facebook(array $source,array $responses): array {
    $identity=p50_live_v3_identity('Facebook',(string)$source['url']);$combined='';$ok=0;$errors=[];$maxMs=0;
    foreach($responses as $label=>$r){if(!empty($r['ok'])){$ok++;$combined.="\n".(string)$r['body']."\n".(string)($r['finalUrl']??'');}else $errors[]=$label.':http_'.($r['status']??0);$maxMs=max($maxMs,(int)($r['timeMs']??0));}
    if($ok===0)return ['state'=>'unknown','error'=>implode(';',$errors),'confidence'=>0,'responseMs'=>$maxMs];
    $live=(bool)preg_match('/"(?:is_live_streaming|isLiveStreaming|is_live)"\s*:\s*true/i',$combined)
        ||(bool)preg_match('/"broadcast_status"\s*:\s*"LIVE"/i',$combined)
        ||(bool)preg_match('#facebook\.com/(?:watch/live|[^"\']+/videos/\d+)#i',$combined);
    if(!$live)return ['state'=>'unknown','error'=>'facebook_no_public_live_signal','confidence'=>0,'responseMs'=>$maxMs];
    $meta=p50_page_metadata($combined,$identity['liveUrl']);$url=(string)($meta['canonical']?:$identity['liveUrl']);
    return ['state'=>'live','confidence'=>92,'responseMs'=>$maxMs,'live'=>[
        'profileId'=>(string)$source['profile_id'],'platform'=>'Facebook','title'=>trim((string)($source['public_name']??'Facebook')).' est en direct',
        'url'=>$url,'thumbnail'=>(string)($meta['image']??''),'confidence'=>92,'startedAt'=>null,'viewers'=>p50_live_v3_viewers($combined),
        'metadata'=>['profileUrl'=>$identity['profileUrl'],'probe'=>'public_live_page'],
    ]];
}

function p50_live_v3_parse_source(array $source,array $responses): array {
    return match((string)$source['platform']){
        'YouTube'=>p50_live_v3_parse_youtube($source,$responses),
        'TikTok'=>p50_live_v3_parse_tiktok($source,$responses),
        'Instagram'=>p50_live_v3_parse_instagram($source,$responses),
        'Facebook'=>p50_live_v3_parse_facebook($source,$responses),
        default=>['state'=>'unknown','error'=>'unsupported_platform','confidence'=>0],
    };
}

function p50_live_v3_scan_batch(array $sources): array {
    $jobs=[];$groups=[];
    foreach($sources as $index=>$source){
        foreach(p50_live_v3_probe_requests($source) as $label=>$job){$jobId=$index.'|'.$label;$jobs[$jobId]=$job;$groups[$index][$label]=$jobId;}
    }
    $raw=p50_live_v3_parallel_fetch($jobs,6);$results=[];
    foreach($sources as $index=>$source){
        $responses=[];foreach((array)($groups[$index]??[]) as $label=>$jobId)$responses[$label]=$raw[$jobId]??[];
        $parsed=p50_live_v3_parse_source($source,$responses);$parsed['source']=$source;
        $parsed['probes']=array_map(static fn($r)=>['ok'=>(bool)($r['ok']??false),'status'=>(int)($r['status']??0),'timeMs'=>(int)($r['timeMs']??0),'error'=>(string)($r['error']??'')],$responses);
        $results[]=$parsed;
    }
    return $results;
}

function p50_live_v3_store(array $live): void {
    $platform=(string)$live['platform'];$profileId=(string)$live['profileId'];$url=(string)$live['url'];
    // Certaines plateformes renvoient une URL de LIVE générique ou canonique
    // commune à plusieurs profils. Le profil doit donc faire partie de la clé.
    $key=hash('sha256',strtolower($profileId.'|'.$platform.'|'.rtrim($url,'/')));
    $endOthers=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=NOW() WHERE profile_id=? AND platform=? AND source='automatic' AND status='live' AND stream_key<>?");
    $endOthers->execute([$profileId,$platform,$key]);
    $title=(string)$live['title'];$safeTitle=function_exists('mb_substr')?mb_substr($title,0,255,'UTF-8'):substr($title,0,255);
    $stmt=db()->prepare("INSERT INTO p50_live_streams(stream_key,profile_id,platform,title,url,thumbnail_url,status,source,confidence,viewers,started_at,last_seen_at,ended_at,metadata)
        VALUES(?,?,?,?,?,?,'live','automatic',?,?,?,NOW(),NULL,?)
        ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),platform=VALUES(platform),title=VALUES(title),url=VALUES(url),thumbnail_url=VALUES(thumbnail_url),status='live',confidence=VALUES(confidence),viewers=VALUES(viewers),started_at=COALESCE(started_at,VALUES(started_at)),last_seen_at=NOW(),ended_at=NULL,metadata=VALUES(metadata)");
    $stmt->execute([$key,$profileId,$platform,$safeTitle,$url,(string)($live['thumbnail']??''),(int)$live['confidence'],$live['viewers']??null,$live['startedAt']??null,json_encode($live['metadata']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}

function p50_live_v3_health_update(array $source,array $result): array {
    $state=(string)($result['state']??'unknown');$profileId=(string)$source['profile_id'];$platform=(string)$source['platform'];$url=(string)$source['url'];
    $urlHash=hash('sha256',strtolower(rtrim($url,'/')));
    $stmt=db()->prepare('SELECT url_hash,consecutive_offline,consecutive_unknown FROM p50_live_source_health WHERE profile_id=? AND platform=? LIMIT 1');$stmt->execute([$profileId,$platform]);$previous=$stmt->fetch()?:[];
    $sameUrl=(string)($previous['url_hash']??'')===$urlHash;
    $offline=$state==='offline'?($sameUrl?(int)($previous['consecutive_offline']??0):0)+1:0;
    $unknown=$state==='unknown'?($sameUrl?(int)($previous['consecutive_unknown']??0):0)+1:0;
    $metadata=['confidence'=>(int)($result['confidence']??0),'probes'=>$result['probes']??[]];
    $upsert=db()->prepare("INSERT INTO p50_live_source_health(profile_id,platform,url_hash,official_url,last_state,consecutive_offline,consecutive_unknown,last_checked_at,last_live_at,response_ms,last_error,metadata)
        VALUES(?,?,?,?,?,?,?,NOW(),IF(?='live',NOW(),NULL),?,?,?)
        ON DUPLICATE KEY UPDATE url_hash=VALUES(url_hash),official_url=VALUES(official_url),last_state=VALUES(last_state),consecutive_offline=VALUES(consecutive_offline),consecutive_unknown=VALUES(consecutive_unknown),last_checked_at=NOW(),last_live_at=IF(VALUES(last_state)='live',NOW(),last_live_at),response_ms=VALUES(response_ms),last_error=VALUES(last_error),metadata=VALUES(metadata)");
    $upsert->execute([$profileId,$platform,$urlHash,$url,$state,$offline,$unknown,$state,(int)($result['responseMs']??0),substr((string)($result['error']??''),0,255),json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    return ['offline'=>$offline,'unknown'=>$unknown];
}

function p50_live_v3_mark_ended(string $profileId,string $platform): void {
    $stmt=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=NOW() WHERE profile_id=? AND platform=? AND source='automatic' AND status='live'");
    $stmt->execute([$profileId,$platform]);
}

function p50_live_v3_active_rows(int $staleMinutes): array {
    $staleMinutes=max(5,min(1440,$staleMinutes));
    db()->exec("UPDATE p50_live_streams SET status='ended',ended_at=NOW() WHERE source='automatic' AND status='live' AND last_seen_at<DATE_SUB(NOW(),INTERVAL {$staleMinutes} MINUTE)");
    $stmt=db()->query("SELECT * FROM p50_live_streams WHERE status='live' ORDER BY COALESCE(started_at,last_seen_at) DESC");$out=[];
    foreach($stmt->fetchAll() as $row)$out[]=[
        'id'=>'auto_'.substr((string)$row['stream_key'],0,18),'profileId'=>(string)$row['profile_id'],'platform'=>(string)$row['platform'],
        'title'=>(string)$row['title'],'url'=>(string)$row['url'],'thumbnail'=>(string)($row['thumbnail_url']??''),'status'=>'live','source'=>'automatic',
        'confidence'=>(int)$row['confidence'],'viewers'=>$row['viewers']!==null?(int)$row['viewers']:null,
        'startedAt'=>p50_live_v3_iso($row['started_at']??null),'lastSeenAt'=>p50_live_v3_iso($row['last_seen_at']??null),'endsAt'=>null,
    ];
    return $out;
}

function p50_live_v3_manual_streams(array $state): array {
    $now=time();$out=[];
    foreach((array)($state['liveStreams']??[]) as $live){
        if(!is_array($live)||($live['status']??'')!=='live'||empty($live['profileId'])||empty($live['url']))continue;
        $end=strtotime((string)($live['endsAt']??''));if($end!==false&&$end>0&&$end<=$now)continue;
        $live['id']=(string)($live['id']??('manual_'.substr(hash('sha256',(string)$live['url']),0,16)));$live['source']='manual';$out[]=$live;
    }
    return $out;
}

function p50_live_v3_dedup(array $automatic,array $manual): array {
    $out=[];$seen=[];
    foreach(array_merge($automatic,$manual) as $stream){
        $key=strtolower(
            trim((string)($stream['profileId']??'')).'|'.
            trim((string)($stream['platform']??'')).'|'.
            rtrim(trim((string)($stream['url']??'')),'/')
        );
        if($key==='||'||isset($seen[$key]))continue;$seen[$key]=true;$out[]=$stream;
    }
    usort($out,static fn($a,$b)=>strcmp((string)($b['startedAt']??$b['lastSeenAt']??''),(string)($a['startedAt']??$a['lastSeenAt']??'')));return $out;
}

function p50_live_v3_cycle_id(): string {
    $raw=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($_GET['cycle']??''))?:'';
    return $raw!==''?substr($raw,0,64):('cycle_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(4)));
}

function p50_live_v3_cycle_key(string $cycleId): string {return 'live_radar_v3_cycle_'.substr(hash('sha256',$cycleId),0,24);}

function p50_live_v3_health_summary(): array {
    $summary=[];foreach(P50_LIVE_PLATFORMS as $platform)$summary[$platform]=['live'=>0,'offline'=>0,'unknown'=>0,'never_checked'=>0];
    try{
        $stmt=db()->query('SELECT platform,last_state,COUNT(*) total FROM p50_live_source_health GROUP BY platform,last_state');
        foreach($stmt->fetchAll() as $row){$p=(string)$row['platform'];$s=(string)$row['last_state'];if(isset($summary[$p]))$summary[$p][$s]=(int)$row['total'];}
    }catch(Throwable){}
    return $summary;
}

p50_live_v3_ensure_schema();p50_de_sync_registry_from_state();
$state=p50_de_load_public_state();$sources=p50_live_v3_sources($state);
$profileFilter=trim((string)($_GET['profileId']??''));if($profileFilter!=='')$sources=array_values(array_filter($sources,static fn($s)=>(string)$s['profile_id']===$profileFilter));
$sourceMap=[];foreach($sources as $source)$sourceMap[(string)$source['source_key']]=$source;

$mode=strtolower((string)($_GET['mode']??'quick'));if(!in_array($mode,['quick','full','profile','status'],true))$mode='quick';
$force=p50_live_v3_bool_query('force')||in_array($mode,['full','profile'],true);$batch=max(1,min(12,(int)($_GET['batch']??8)));
$refresh=45;$stale=90;$lastScan=(string)p50_de_get_setting('live_radar_last_scan_at','');$lastTs=$lastScan!==''?(strtotime($lastScan)?:0):0;$canScan=$mode!=='status'&&($force||(time()-$lastTs)>=$refresh);
$cycleId=null;$cycleComplete=true;$cycleScanned=0;$cycleFound=0;$cycleTotal=count($sources);$selected=[];$manifest=null;

if($mode==='full'){
    $cycleId=p50_live_v3_cycle_id();$cycleKey=p50_live_v3_cycle_key($cycleId);$manifest=p50_de_get_setting($cycleKey,null);
    $valid=is_array($manifest)&&isset($manifest['keys'],$manifest['cursor'],$manifest['createdAt'])&&strtotime((string)$manifest['createdAt'])>time()-900;
    if(!$valid){$manifest=['cycleId'=>$cycleId,'createdAt'=>gmdate(DATE_ATOM),'keys'=>array_values(array_keys($sourceMap)),'cursor'=>0,'scanned'=>0,'found'=>0,'complete'=>false];}
    $cycleTotal=count((array)$manifest['keys']);$cursor=max(0,(int)$manifest['cursor']);$keys=array_slice((array)$manifest['keys'],$cursor,$batch);
    foreach($keys as $key)if(isset($sourceMap[$key]))$selected[]=$sourceMap[$key];
    $cycleScanned=(int)$manifest['scanned'];$cycleFound=(int)$manifest['found'];$cycleComplete=$cursor>=$cycleTotal;
}elseif($mode==='profile'){
    $selected=array_slice($sources,0,$batch);$cycleTotal=count($sources);$cycleComplete=true;
}else{
    $selected=array_slice($sources,0,$batch);$cycleComplete=true;
}

$scanPerformed=false;$busy=false;$foundThisPass=0;$diagnostics=[];$platformStats=[];
foreach(P50_LIVE_PLATFORMS as $platform)$platformStats[$platform]=['known'=>count(array_filter($sources,static fn($s)=>(string)$s['platform']===$platform)),'scanned'=>0,'found'=>0];

$lock=false;if($canScan&&$selected){try{$lock=(int)db()->query("SELECT GET_LOCK('pass50_live_radar_v3',0)")->fetchColumn()===1;}catch(Throwable){}}
if($canScan&&$selected&&!$lock)$busy=true;

if($canScan&&$selected&&$lock){
    $scanPerformed=true;$results=p50_live_v3_scan_batch($selected);
    foreach($results as $result){
        $source=$result['source'];$platform=(string)$source['platform'];$platformStats[$platform]['scanned']++;
        $health=p50_live_v3_health_update($source,$result);$stateValue=(string)$result['state'];
        if($stateValue==='live'&&!empty($result['live'])){p50_live_v3_store($result['live']);$foundThisPass++;$platformStats[$platform]['found']++;}
        elseif($stateValue==='offline'&&$health['offline']>=2)p50_live_v3_mark_ended((string)$source['profile_id'],$platform);
        $diagnostics[]=[
            'profileId'=>(string)$source['profile_id'],'name'=>(string)$source['public_name'],'platform'=>$platform,'state'=>$stateValue,
            'confidence'=>(int)($result['confidence']??0),'error'=>(string)($result['error']??''),'probes'=>$result['probes']??[],
        ];
    }
    $lastScan=gmdate(DATE_ATOM);p50_de_set_setting('live_radar_last_scan_at',$lastScan);
    if($mode==='full'&&is_array($manifest)){
        $manifest['cursor']=min($cycleTotal,(int)$manifest['cursor']+count($keys));
        $manifest['scanned']=(int)$manifest['scanned']+count($selected);$manifest['found']=(int)$manifest['found']+$foundThisPass;
        $manifest['complete']=(int)$manifest['cursor']>=$cycleTotal;$manifest['updatedAt']=gmdate(DATE_ATOM);
        $cycleScanned=(int)$manifest['scanned'];$cycleFound=(int)$manifest['found'];$cycleComplete=(bool)$manifest['complete'];
        p50_de_set_setting(p50_live_v3_cycle_key((string)$cycleId),$manifest);
        if($cycleComplete)p50_de_set_setting('live_radar_v3_last_full_sweep',['completedAt'=>gmdate(DATE_ATOM),'total'=>$cycleTotal,'found'=>$cycleFound]);
    }
    try{db()->query("SELECT RELEASE_LOCK('pass50_live_radar_v3')");}catch(Throwable){}
}

$automatic=p50_live_v3_active_rows($stale);$manual=p50_live_v3_manual_streams($state);$streams=p50_live_v3_dedup($automatic,$manual);
$coverage=$cycleTotal>0?(int)round(($mode==='full'?$cycleScanned:count($selected))*100/$cycleTotal):100;
$lastFull=p50_de_get_setting('live_radar_v3_last_full_sweep',null);

json_response([
    'ok'=>true,'liveStreams'=>$streams,
    'radar'=>[
        'version'=>3,'mode'=>$mode,'scanPerformed'=>$scanPerformed,'busy'=>$busy,'forced'=>$force,'lastScanAt'=>$lastScan?:null,
        'cycleId'=>$cycleId,'cycleComplete'=>$cycleComplete,'cycleTotal'=>$cycleTotal,'cycleScanned'=>$cycleScanned,
        'sourcesScannedThisPass'=>count($selected),'livesFoundThisPass'=>$foundThisPass,'livesFoundInCycle'=>$cycleFound,'coveragePercent'=>$coverage,
        'officialSourcesKnown'=>count($sources),'platforms'=>$platformStats,'health'=>p50_live_v3_health_summary(),'lastFullSweep'=>$lastFull,
        'refreshSeconds'=>$refresh,'staleMinutes'=>$stale,'diagnostics'=>$diagnostics,
    ],
]);
