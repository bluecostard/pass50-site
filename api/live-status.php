<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('GET');
set_time_limit(55);

/**
 * PASS50 Radar LIVE V2
 * - détection automatique des directs YouTube et TikTok ;
 * - rotation indépendante par plateforme ;
 * - contrôle forcé depuis le bouton d'administration ;
 * - fusion avec les lives manuels du state public ;
 * - retrait automatique des directs terminés.
 */

function p50_live_ensure_schema(): void {
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
}

function p50_live_iso(?string $mysql): ?string {
    if (!$mysql) return null;
    try { return (new DateTimeImmutable($mysql, new DateTimeZone('UTC')))->format(DATE_ATOM); }
    catch (Throwable) { return null; }
}

function p50_live_youtube_base(string $url): string {
    $parts=parse_url($url);
    if(!$parts||empty($parts['host']))return '';
    $scheme=(string)($parts['scheme']??'https');
    $host=(string)$parts['host'];
    $path=rtrim((string)($parts['path']??''),'/');
    if(str_contains(strtolower($host),'youtu.be'))return $url;
    if(preg_match('#/(watch|shorts|embed|live)(?:/|$)#i',$path)||!empty($parts['query']))return $url;
    $path=preg_replace('#/(featured|videos|shorts|streams|about|community)$#i','',$path)??$path;
    if($path==='')return '';
    return $scheme.'://'.$host.rtrim($path,'/').'/live';
}

function p50_live_video_id(string $url,string $html=''): string {
    $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));$path=(string)($parts['path']??'');
    if(str_contains($host,'youtu.be'))return trim($path,'/');
    parse_str((string)($parts['query']??''),$query);
    if(!empty($query['v']))return (string)$query['v'];
    if(preg_match('#/(?:shorts|embed|live)/([A-Za-z0-9_-]{6,})#',$path,$m))return $m[1];
    foreach([
        '/"videoId"\s*:\s*"([A-Za-z0-9_-]{6,})"/',
        '/youtube\.com\/watch\?v=([A-Za-z0-9_-]{6,})/',
    ] as $pattern)if(preg_match($pattern,$html,$m))return $m[1];
    return '';
}

function p50_live_unescape(string $value): string {
    $value=str_replace(['\\u0026','\\u003d','\\/'],['&','=','/'],$value);
    return html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');
}

function p50_live_scan_youtube(array $source): ?array {
    $liveUrl=p50_live_youtube_base((string)$source['url']);
    if($liveUrl==='')return null;
    $r=p50_http_fetch($liveUrl,8,'text/html,*/*;q=0.7');
    if(!$r['ok']||$r['body']==='')return null;
    $html=$r['body'];
    $isLive=(bool)preg_match('/"isLiveNow"\s*:\s*true/i',$html)
        ||(bool)preg_match('/itemprop=["\']isLiveBroadcast["\'][^>]+content=["\']True["\']/i',$html)
        ||((bool)preg_match('/"isLiveContent"\s*:\s*true/i',$html)&&(bool)preg_match('/"playabilityStatus"\s*:\s*\{[^}]*"status"\s*:\s*"OK"/is',$html));
    if(!$isLive)return null;

    $meta=p50_page_metadata($html,(string)($r['finalUrl']?:$liveUrl));
    $videoId=p50_live_video_id((string)($meta['canonical']?:$r['finalUrl']),$html);
    $url=$videoId!==''?'https://www.youtube.com/watch?v='.$videoId:(string)($meta['canonical']?:$r['finalUrl']);
    if(!filter_var($url,FILTER_VALIDATE_URL))return null;
    $title=trim((string)($meta['title']??''));
    $title=preg_replace('/\s*-\s*YouTube\s*$/iu','',$title)??$title;
    if($title==='')$title='Direct en cours';
    $thumbnail=(string)($meta['image']??'');
    if($thumbnail===''&&$videoId!=='')$thumbnail='https://i.ytimg.com/vi/'.rawurlencode($videoId).'/hqdefault.jpg';
    $started=null;
    if(preg_match('/"startTimestamp"\s*:\s*"([^"]+)"/',$html,$m)){
        try{$started=(new DateTimeImmutable(p50_live_unescape($m[1])))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable){}
    }
    $viewers=null;
    foreach(['/"concurrentViewers"\s*:\s*"(\d+)"/','/"viewCount"\s*:\s*"(\d+)"/'] as $pattern){if(preg_match($pattern,$html,$m)){$viewers=(int)$m[1];break;}}
    return [
        'profileId'=>(string)$source['profile_id'],
        'platform'=>'YouTube','title'=>$title,'url'=>$url,'thumbnail'=>$thumbnail,
        'confidence'=>max(90,(int)($source['confidence']??90)),
        'startedAt'=>$started,'viewers'=>$viewers,
        'metadata'=>['channelUrl'=>(string)$source['url'],'videoId'=>$videoId,'checkedUrl'=>$liveUrl],
    ];
}

function p50_live_tiktok_identity(string $url): array {
    $parts=parse_url(trim($url));
    if(!$parts||empty($parts['host'])||!str_contains(strtolower((string)$parts['host']),'tiktok.com'))return ['handle'=>'','profileUrl'=>'','liveUrl'=>''];
    $path=(string)($parts['path']??'');
    if(!preg_match('#/@([A-Za-z0-9._-]+)#',$path,$m))return ['handle'=>'','profileUrl'=>'','liveUrl'=>''];
    $handle=$m[1];
    $profile='https://www.tiktok.com/@'.$handle;
    return ['handle'=>$handle,'profileUrl'=>$profile,'liveUrl'=>$profile.'/live'];
}

function p50_live_fetch_tiktok(string $url,int $timeout=5,string $accept='text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.7'): array {
    if(!p50_public_http_url($url))return ['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>$url,'contentType'=>'','error'=>'URL distante refusée'];
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
        CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>min(3,$timeout),CURLOPT_ENCODING=>'',
        CURLOPT_USERAGENT=>'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        CURLOPT_HTTPHEADER=>[
            'Accept: '.$accept,
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.7',
            'Cache-Control: no-cache','Pragma: no-cache','DNT: 1',
        ],
        CURLOPT_HEADER=>false,
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $finalUrl=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);$contentType=strtolower((string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE));
    $error=curl_error($ch);curl_close($ch);
    return ['ok'=>is_string($body)&&$status>=200&&$status<400,'status'=>$status,'body'=>is_string($body)?$body:'','finalUrl'=>$finalUrl?:$url,'contentType'=>$contentType,'error'=>$error];
}

function p50_live_tiktok_room_id(string $body): string {
    foreach([
        '/"roomId"\s*:\s*"?([1-9]\d{5,})"?/i',
        '/"room_id"\s*:\s*"?([1-9]\d{5,})"?/i',
        '/"liveRoomId"\s*:\s*"?([1-9]\d{5,})"?/i',
        '/"webcastRoomId"\s*:\s*"?([1-9]\d{5,})"?/i',
    ] as $pattern)if(preg_match($pattern,$body,$m))return (string)$m[1];
    return '';
}

function p50_live_tiktok_viewers(string $body): ?int {
    foreach([
        '/"user_count"\s*:\s*"?(\d+)"?/i',
        '/"viewerCount"\s*:\s*"?(\d+)"?/i',
        '/"liveRoomUserCount"\s*:\s*"?(\d+)"?/i',
        '/"roomUserCount"\s*:\s*"?(\d+)"?/i',
    ] as $pattern)if(preg_match($pattern,$body,$m))return (int)$m[1];
    return null;
}

function p50_live_scan_tiktok(array $source): ?array {
    $identity=p50_live_tiktok_identity((string)$source['url']);
    if($identity['handle']==='')return null;

    $apiUrl='https://www.tiktok.com/api-live/user/room/?aid=1988&uniqueId='.rawurlencode($identity['handle']);
    $api=p50_live_fetch_tiktok($apiUrl,5,'application/json,text/plain,*/*');
    $roomId=$api['ok']?p50_live_tiktok_room_id($api['body']):'';
    $body=$api['body'];$checkedUrl=$apiUrl;$finalUrl=$api['finalUrl'];

    if($roomId===''){
        $page=p50_live_fetch_tiktok($identity['liveUrl'],5);
        if(!$page['ok']||$page['body']==='')return null;
        $body=$page['body'];$checkedUrl=$identity['liveUrl'];$finalUrl=$page['finalUrl'];
        $roomId=p50_live_tiktok_room_id($body);
        $strongLive=(bool)preg_match('/"(?:isLive|isLiveStreaming)"\s*:\s*true/i',$body)
            ||(bool)preg_match('/"(?:liveStatus|live_status)"\s*:\s*[12](?:\D|$)/i',$body)
            ||(bool)preg_match('/"LiveRoom"/i',$body);
        $finalPath=(string)(parse_url((string)$finalUrl,PHP_URL_PATH)??'');
        if($roomId===''||(!$strongLive&&!preg_match('#/@[A-Za-z0-9._-]+/live/?$#i',$finalPath)))return null;
    }

    $meta=p50_page_metadata($body,(string)($finalUrl?:$identity['liveUrl']));
    $title=trim((string)($meta['title']??''));
    $title=preg_replace('/\s*\|\s*TikTok\s*$/iu','',$title)??$title;
    if($title===''||preg_match('/^(TikTok|Make Your Day)$/iu',$title))$title=trim((string)($source['public_name']??''));
    if($title==='')$title='Direct TikTok en cours';
    elseif(!preg_match('/\b(direct|live)\b/iu',$title))$title.=' est en direct';

    return [
        'profileId'=>(string)$source['profile_id'],'platform'=>'TikTok','title'=>$title,
        'url'=>$identity['liveUrl'],'thumbnail'=>(string)($meta['image']??''),
        'confidence'=>max(90,(int)($source['confidence']??90)),'startedAt'=>null,
        'viewers'=>p50_live_tiktok_viewers($body),
        'metadata'=>[
            'profileUrl'=>$identity['profileUrl'],'handle'=>'@'.$identity['handle'],
            'roomId'=>$roomId,'checkedUrl'=>$checkedUrl,
        ],
    ];
}

function p50_live_sources(): array {
    $threshold=p50_de_threshold();
    $stmt=db()->prepare("SELECT r.profile_id,r.public_name,r.handle,s.platform,s.normalized_url url,s.confidence
        FROM p50_profile_registry r
        JOIN p50_social_links s ON s.profile_id=r.profile_id
        WHERE r.alive=1 AND s.platform IN ('YouTube','TikTok') AND s.status='verified' AND s.confidence>=?
        ORDER BY s.platform,r.public_name");
    $stmt->execute([$threshold]);
    $rows=$stmt->fetchAll();
    $seen=[];$out=[];
    foreach($rows as $row){
        $platform=(string)$row['platform'];$id=(string)$row['profile_id'];$key=$platform.'|'.$id;
        if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$row;
    }

    // Secours : liens publics déjà publiés dans le state, même si la table moteur
    // n'a pas encore été resynchronisée après une mise à jour GitHub.
    $state=p50_de_load_public_state();
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id'])||array_key_exists('alive',$profile)&&empty($profile['alive']))continue;
        foreach(['YouTube','TikTok'] as $platform){
            $id=(string)$profile['id'];$key=$platform.'|'.$id;
            if(isset($seen[$key]))continue;
            $url=trim((string)(($profile['links']??[])[$platform]??''));
            if($url===''||p50_platform($url)!==$platform)continue;
            if($platform==='YouTube'&&preg_match('#/(results|search)(?:/|\?)#i',$url))continue;
            if($platform==='TikTok'&&p50_live_tiktok_identity($url)['handle']==='')continue;
            $check=(string)(($profile['linkChecks']??[])[$platform]['status']??'');
            $confidence=in_array($check,['owner_verified','manual_verified','ok'],true)?95:90;
            $seen[$key]=true;
            $out[]=['profile_id'=>$id,'public_name'=>(string)($profile['name']??$id),'handle'=>(string)($profile['handle']??''),'platform'=>$platform,'url'=>$url,'confidence'=>$confidence];
        }
    }
    usort($out,static fn($a,$b)=>strcmp((string)$a['platform'],(string)$b['platform'])?:strnatcasecmp((string)$a['public_name'],(string)$b['public_name']));
    return $out;
}

function p50_live_store(array $live): void {
    $platform=(string)$live['platform'];
    $key=hash('sha256',$platform.'|'.strtolower(rtrim((string)$live['url'],'/')));
    $safeTitle=function_exists('mb_substr')?mb_substr((string)$live['title'],0,255,'UTF-8'):substr((string)$live['title'],0,255);
    $stmt=db()->prepare("INSERT INTO p50_live_streams(stream_key,profile_id,platform,title,url,thumbnail_url,status,source,confidence,viewers,started_at,last_seen_at,ended_at,metadata)
        VALUES(?,?,?,?,?,?,'live','automatic',?,?,?,NOW(),NULL,?)
        ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),platform=VALUES(platform),title=VALUES(title),url=VALUES(url),thumbnail_url=VALUES(thumbnail_url),status='live',confidence=VALUES(confidence),viewers=VALUES(viewers),started_at=COALESCE(started_at,VALUES(started_at)),last_seen_at=NOW(),ended_at=NULL,metadata=VALUES(metadata)");
    $stmt->execute([
        $key,(string)$live['profileId'],$platform,$safeTitle,(string)$live['url'],(string)$live['thumbnail'],
        (int)$live['confidence'],$live['viewers'],$live['startedAt'],json_encode($live['metadata'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]);
}

function p50_live_mark_ended(string $profileId,string $platform): void {
    $stmt=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=NOW() WHERE profile_id=? AND platform=? AND source='automatic' AND status='live'");
    $stmt->execute([$profileId,$platform]);
}

function p50_live_active_rows(int $staleMinutes): array {
    $staleMinutes=max(1,min(1440,$staleMinutes));
    db()->exec("UPDATE p50_live_streams SET status='ended',ended_at=NOW() WHERE source='automatic' AND status='live' AND last_seen_at<DATE_SUB(NOW(),INTERVAL {$staleMinutes} MINUTE)");
    $stmt=db()->query("SELECT * FROM p50_live_streams WHERE status='live' ORDER BY COALESCE(started_at,last_seen_at) DESC");
    $out=[];
    foreach($stmt->fetchAll() as $row){
        $out[]=[
            'id'=>'auto_'.substr((string)$row['stream_key'],0,18),'profileId'=>(string)$row['profile_id'],'platform'=>(string)$row['platform'],
            'title'=>(string)$row['title'],'url'=>(string)$row['url'],'thumbnail'=>(string)($row['thumbnail_url']??''),'status'=>'live','source'=>'automatic',
            'confidence'=>(int)$row['confidence'],'viewers'=>$row['viewers']!==null?(int)$row['viewers']:null,
            'startedAt'=>p50_live_iso($row['started_at']??null)??p50_live_iso($row['last_seen_at']??null),'endsAt'=>null,
        ];
    }
    return $out;
}

function p50_live_manual_from_state(): array {
    $state=p50_de_load_public_state();$now=time();$out=[];
    foreach((array)($state['liveStreams']??[]) as $live){
        if(!is_array($live)||($live['status']??'')!=='live'||empty($live['profileId'])||empty($live['url']))continue;
        $endTs=0;$ends=trim((string)($live['endsAt']??''));
        if($ends!==''){$parsed=strtotime($ends);if($parsed!==false)$endTs=$parsed;}
        if($endTs>0&&$endTs<=$now)continue;
        $startTs=0;
        foreach(['startedAt','detectedAt','createdAt','updatedAt'] as $key){
            $value=trim((string)($live[$key]??''));if($value==='')continue;
            $parsed=strtotime($value);if($parsed!==false){$startTs=$parsed;break;}
        }
        if($startTs<=0&&$endTs<=0)continue;
        if($endTs<=0&&$startTs>0&&($now-$startTs)>8*3600)continue;
        $live['id']=(string)($live['id']??('manual_'.substr(hash('sha256',(string)$live['url']),0,16)));
        $live['source']='manual';$out[]=$live;
    }
    return $out;
}

function p50_live_scan_platform(array $source): ?array {
    $platform=(string)($source['platform']??'');
    if($platform==='TikTok')return p50_live_scan_tiktok($source);
    if($platform==='YouTube')return p50_live_scan_youtube($source);
    return null;
}

p50_live_ensure_schema();
p50_de_sync_registry_from_state();

global $config;
$force=isset($_GET['force'])&&in_array(strtolower((string)$_GET['force']),['1','true','yes'],true);
$refresh=max(30,min(300,(int)($config['data_engine']['live_refresh_seconds']??50)));
$stale=max(20,min(60,(int)($config['data_engine']['live_stale_minutes']??25)));
$sources=p50_live_sources();
$platformSources=['YouTube'=>[],'TikTok'=>[]];
foreach($sources as $source){$platform=(string)($source['platform']??'');if(isset($platformSources[$platform]))$platformSources[$platform][]=$source;}

$lastScan=(string)p50_de_get_setting('live_radar_last_scan_at','');
$lastTs=$lastScan!==''?(strtotime($lastScan)?:0):0;
$canScan=$force||(time()-$lastTs)>=$refresh;
$scanPerformed=false;$scanned=0;$found=0;$stats=[];
$limits=$force?['YouTube'=>3,'TikTok'=>7]:['YouTube'=>2,'TikTok'=>4];

$lockAcquired=false;
if($canScan){try{$lockAcquired=(int)db()->query("SELECT GET_LOCK('pass50_live_radar',0)")->fetchColumn()===1;}catch(Throwable){}}

if($canScan&&$lockAcquired){
    $scanPerformed=true;
    foreach($platformSources as $platform=>$items){
        $total=count($items);$platformScanned=0;$platformFound=0;
        $cursorKey='live_radar_cursor_'.strtolower($platform);
        $cursor=max(0,(int)p50_de_get_setting($cursorKey,0));
        if($total>0&&$cursor>=$total)$cursor=0;
        $limit=min((int)$limits[$platform],$total);
        for($i=0;$i<$limit;$i++){
            $source=$items[($cursor+$i)%$total];$platformScanned++;$scanned++;
            try{$live=p50_live_scan_platform($source);}catch(Throwable){$live=null;}
            if($live){p50_live_store($live);$platformFound++;$found++;}
            else p50_live_mark_ended((string)$source['profile_id'],$platform);
        }
        if($total>0)p50_de_set_setting($cursorKey,($cursor+$platformScanned)%$total);
        $stats[$platform]=['known'=>$total,'scanned'=>$platformScanned,'found'=>$platformFound,'batchSize'=>$limits[$platform]];
    }
    $lastScan=gmdate(DATE_ATOM);p50_de_set_setting('live_radar_last_scan_at',$lastScan);
    try{db()->query("SELECT RELEASE_LOCK('pass50_live_radar')");}catch(Throwable){}
}else{
    foreach($platformSources as $platform=>$items)$stats[$platform]=['known'=>count($items),'scanned'=>0,'found'=>0,'batchSize'=>$limits[$platform]];
}

$streams=array_merge(p50_live_active_rows($stale),p50_live_manual_from_state());
$seen=[];$dedup=[];
foreach($streams as $stream){$key=strtolower(rtrim((string)($stream['url']??''),'/'));if($key===''||isset($seen[$key]))continue;$seen[$key]=true;$dedup[]=$stream;}
usort($dedup,static fn($a,$b)=>strcmp((string)($b['startedAt']??''),(string)($a['startedAt']??'')));

json_response([
    'ok'=>true,'liveStreams'=>$dedup,
    'radar'=>[
        'version'=>2,'mode'=>'YouTube + TikTok automatiques, autres réseaux hybrides','lastScanAt'=>$lastScan?:null,
        'scanPerformed'=>$scanPerformed,'forced'=>$force,'profilesScanned'=>$scanned,'livesFoundThisPass'=>$found,
        'youtubeProfilesKnown'=>(int)($stats['YouTube']['known']??0),'tiktokProfilesKnown'=>(int)($stats['TikTok']['known']??0),
        'platforms'=>$stats,'refreshSeconds'=>$refresh,'staleMinutes'=>$stale,
    ],
]);
