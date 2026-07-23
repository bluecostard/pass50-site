<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('GET');
set_time_limit(55);

/**
 * PASS50 Radar LIVE V1
 * - automatique et gratuit sur les chaînes YouTube officielles vérifiées ;
 * - rotation par petits lots pour ne pas surcharger l'hébergement ;
 * - fusion avec les lives manuels du state public ;
 * - retrait automatique lorsqu'une chaîne contrôlée n'est plus en direct.
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

function p50_live_sources(): array {
    $threshold=p50_de_threshold();
    $stmt=db()->prepare("SELECT r.profile_id,r.public_name,r.handle,s.normalized_url url,s.confidence
        FROM p50_profile_registry r
        JOIN p50_social_links s ON s.profile_id=r.profile_id
        WHERE r.alive=1 AND s.platform='YouTube' AND s.status='verified' AND s.confidence>=?
        ORDER BY r.public_name");
    $stmt->execute([$threshold]);
    $rows=$stmt->fetchAll();
    $seen=[];$out=[];
    foreach($rows as $row){$id=(string)$row['profile_id'];if(isset($seen[$id]))continue;$seen[$id]=true;$out[]=$row;}

    // Secours : liens publics déjà publiés dans le state, même si la table moteur
    // n'a pas encore été resynchronisée après une mise à jour GitHub.
    $state=p50_de_load_public_state();
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id'])||isset($seen[(string)$profile['id']])||array_key_exists('alive',$profile)&&empty($profile['alive']))continue;
        $url=trim((string)(($profile['links']??[])['YouTube']??''));
        if($url===''||p50_platform($url)!=='YouTube'||preg_match('#/(results|search)(?:/|\?)#i',$url))continue;
        $seen[(string)$profile['id']]=true;
        $out[]=['profile_id'=>(string)$profile['id'],'public_name'=>(string)($profile['name']??$profile['id']),'handle'=>(string)($profile['handle']??''),'url'=>$url,'confidence'=>90];
    }
    usort($out,static fn($a,$b)=>strnatcasecmp((string)$a['public_name'],(string)$b['public_name']));
    return $out;
}

function p50_live_store(array $live): void {
    $key=hash('sha256','YouTube|'.strtolower(rtrim((string)$live['url'],'/')));
    $safeTitle=function_exists('mb_substr')?mb_substr((string)$live['title'],0,255,'UTF-8'):substr((string)$live['title'],0,255);
    $stmt=db()->prepare("INSERT INTO p50_live_streams(stream_key,profile_id,platform,title,url,thumbnail_url,status,source,confidence,viewers,started_at,last_seen_at,ended_at,metadata)
        VALUES(?,?,?,?,?,?,'live','automatic',?,?,?,NOW(),NULL,?)
        ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),title=VALUES(title),thumbnail_url=VALUES(thumbnail_url),status='live',confidence=VALUES(confidence),viewers=VALUES(viewers),started_at=COALESCE(started_at,VALUES(started_at)),last_seen_at=NOW(),ended_at=NULL,metadata=VALUES(metadata)");
    $stmt->execute([
        $key,(string)$live['profileId'],'YouTube',$safeTitle,(string)$live['url'],(string)$live['thumbnail'],
        (int)$live['confidence'],$live['viewers'],$live['startedAt'],json_encode($live['metadata'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]);
}

function p50_live_mark_youtube_ended(string $profileId): void {
    $stmt=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=NOW() WHERE profile_id=? AND platform='YouTube' AND source='automatic' AND status='live'");
    $stmt->execute([$profileId]);
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
        $ends=(string)($live['endsAt']??'');
        if($ends!==''&&strtotime($ends)!==false&&strtotime($ends)<=$now)continue;
        $live['id']=(string)($live['id']??('manual_'.substr(hash('sha256',(string)$live['url']),0,16)));
        $live['source']='manual';$out[]=$live;
    }
    return $out;
}

p50_live_ensure_schema();
p50_de_sync_registry_from_state();

global $config;
$batch=max(1,min(12,(int)($config['data_engine']['live_batch_size']??6)));
$refresh=max(30,min(300,(int)($config['data_engine']['live_refresh_seconds']??50)));
$stale=max(15,min(180,(int)($config['data_engine']['live_stale_minutes']??45)));
$sources=p50_live_sources();$total=count($sources);$scanned=0;$found=0;$scanPerformed=false;
$lastScan=(string)p50_de_get_setting('live_radar_last_scan_at','');
$lastTs=$lastScan!==''?(strtotime($lastScan)?:0):0;
$canScan=(time()-$lastTs)>=$refresh;

$lockAcquired=false;
if($canScan){
    try{$lockAcquired=(int)db()->query("SELECT GET_LOCK('pass50_live_radar',0)")->fetchColumn()===1;}catch(Throwable){}
}

if($canScan&&$lockAcquired&&$total>0){
    $scanPerformed=true;
    $cursor=max(0,(int)p50_de_get_setting('live_radar_cursor',0));
    if($cursor>=$total)$cursor=0;
    for($i=0;$i<$batch&&$i<$total;$i++){
        $source=$sources[($cursor+$i)%$total];$scanned++;
        try{$live=p50_live_scan_youtube($source);}catch(Throwable){$live=null;}
        if($live){p50_live_store($live);$found++;}else p50_live_mark_youtube_ended((string)$source['profile_id']);
    }
    $cursor=($cursor+$scanned)%max(1,$total);
    $lastScan=gmdate(DATE_ATOM);
    p50_de_set_setting('live_radar_cursor',$cursor);
    p50_de_set_setting('live_radar_last_scan_at',$lastScan);
    try{db()->query("SELECT RELEASE_LOCK('pass50_live_radar')");}catch(Throwable){}
}

$streams=array_merge(p50_live_active_rows($stale),p50_live_manual_from_state());
$seen=[];$dedup=[];
foreach($streams as $stream){$key=strtolower(rtrim((string)($stream['url']??''),'/'));if($key===''||isset($seen[$key]))continue;$seen[$key]=true;$dedup[]=$stream;}
usort($dedup,static fn($a,$b)=>strcmp((string)($b['startedAt']??''),(string)($a['startedAt']??'')));

json_response([
    'ok'=>true,'liveStreams'=>$dedup,
    'radar'=>[
        'version'=>1,'mode'=>'YouTube automatique + autres réseaux hybrides','lastScanAt'=>$lastScan?:null,
        'scanPerformed'=>$scanPerformed,'profilesScanned'=>$scanned,'livesFoundThisPass'=>$found,
        'youtubeProfilesKnown'=>$total,'batchSize'=>$batch,'refreshSeconds'=>$refresh,'staleMinutes'=>$stale,
    ],
]);
