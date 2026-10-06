<?php
declare(strict_types=1);

function p50_live_v4_ensure_dismissals(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS p50_live_dismissals (
        stream_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,
        profile_id VARCHAR(100) NOT NULL,
        platform VARCHAR(32) NOT NULL,
        url_hash CHAR(64) CHARACTER SET ascii NOT NULL,
        dismissed_by VARCHAR(100) NULL,
        reason VARCHAR(120) NOT NULL DEFAULT 'false_positive',
        dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_p50_live_dismissals_profile (profile_id,platform,dismissed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function p50_live_v4_event_identity(array $live): string {
    $metadata=is_array($live['metadata']??null)?$live['metadata']:[];
    foreach(['roomId','videoId','broadcastId','broadcast_id'] as $field){
        $value=trim((string)($metadata[$field]??''));
        if($value==='')continue;
        $value=preg_replace('/[^A-Za-z0-9._:-]/','',$value)??'';
        if($value!=='')return substr($value,0,128);
    }
    return '';
}
function p50_live_v4_stream_key(array $live): string {
    $eventId=p50_live_v4_event_identity($live);
    $identity=$eventId!==''?'event:'.$eventId:'url:'.rtrim((string)$live['url'],'/');
    return hash('sha256',strtolower((string)$live['profileId'].'|'.(string)$live['platform'].'|'.$identity));
}
function p50_live_v4_profile_dismiss_key(string $profileId, string $platform): string {
    return hash('sha256', strtolower('profile_dismiss|'.$profileId.'|'.$platform));
}
function p50_live_v4_is_dismissed(string $key): bool {
    p50_live_v4_ensure_dismissals();
    // TTL 7j : un dismiss admin ne doit pas bloquer définitivement, mais assez long pour casser les boucles FP.
    $stmt=db()->prepare('SELECT 1 FROM p50_live_dismissals WHERE stream_key=? AND dismissed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) LIMIT 1');
    $stmt->execute([$key]);
    return (bool)$stmt->fetchColumn();
}
function p50_live_v4_is_profile_suppressed(string $profileId, string $platform): bool {
    return p50_live_v4_is_dismissed(p50_live_v4_profile_dismiss_key($profileId, $platform));
}
function p50_live_v4_live_is_blocked(array $live): bool {
    $profileId=(string)($live['profileId']??'');$platform=(string)($live['platform']??'');
    if($profileId===''||$platform==='')return false;
    return p50_live_v4_is_dismissed(p50_live_v4_stream_key($live))||p50_live_v4_is_profile_suppressed($profileId, $platform);
}

/** Preuve minimale avant écriture status=live (défense en profondeur vs parsers). */
function p50_live_v4_is_publishable_proof(array $live): bool {
    $platform=strcasecmp((string)($live['platform']??''),'')===0?'':(string)$live['platform'];
    $meta=is_array($live['metadata']??null)?$live['metadata']:[];
    if(strcasecmp($platform,'TikTok')===0){
        $strict=is_array($meta['strictApiLabels']??null)?$meta['strictApiLabels']:[];
        return $strict!==[]&&trim((string)($meta['roomId']??''))!=='';
    }
    if(strcasecmp($platform,'YouTube')===0){
        return ($meta['liveSignal']??'')==='isLiveNow'&&trim((string)($meta['videoId']??$live['videoId']??''))!=='';
    }
    return true;
}

function p50_live_v4_store_live(array $live): bool {
    if(!p50_live_v4_is_publishable_proof($live)){
        p50_live_v4_store_candidate($live,'insufficient_publish_proof');
        return false;
    }
    $key=p50_live_v4_stream_key($live);$profileId=(string)$live['profileId'];$platform=(string)$live['platform'];
    if(p50_live_v4_live_is_blocked($live)){$stmt=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()),metadata=JSON_SET(COALESCE(metadata,'{}'),'$.endReason','manually_dismissed') WHERE profile_id=? AND platform=? AND source='automatic' AND status IN ('live','unconfirmed')");$stmt->execute([$profileId,$platform]);return false;}
    $end=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()) WHERE profile_id=? AND platform=? AND source='automatic' AND status IN ('live','unconfirmed') AND stream_key<>?");$end->execute([$profileId,$platform,$key]);
    $title=(string)$live['title'];$safeTitle=function_exists('mb_substr')?mb_substr($title,0,255,'UTF-8'):substr($title,0,255);
    $stmt=db()->prepare("INSERT INTO p50_live_streams(stream_key,profile_id,platform,title,url,thumbnail_url,status,source,confidence,viewers,started_at,last_seen_at,ended_at,metadata) VALUES(?,?,?,?,?,?,'live','automatic',?,?,?,UTC_TIMESTAMP(),NULL,?) ON DUPLICATE KEY UPDATE title=VALUES(title),url=VALUES(url),thumbnail_url=VALUES(thumbnail_url),status='live',confidence=VALUES(confidence),viewers=VALUES(viewers),started_at=COALESCE(started_at,VALUES(started_at)),last_seen_at=UTC_TIMESTAMP(),ended_at=NULL,metadata=VALUES(metadata)");
    $stmt->execute([$key,$profileId,$platform,$safeTitle,(string)$live['url'],(string)($live['thumbnail']??''),(int)$live['confidence'],$live['viewers']??null,$live['startedAt']??null,json_encode($live['metadata']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    return true;
}
function p50_live_v4_store_candidate(array $live,string $reason): void {
    $key=p50_live_v4_stream_key($live);if(p50_live_v4_live_is_blocked($live))return;$title=(string)$live['title'];$safeTitle=function_exists('mb_substr')?mb_substr($title,0,255,'UTF-8'):substr($title,0,255);$metadata=(array)($live['metadata']??[]);$metadata['candidateReason']=$reason;$metadata['candidateSeenAt']=gmdate(DATE_ATOM);
    $stmt=db()->prepare("INSERT INTO p50_live_streams(stream_key,profile_id,platform,title,url,thumbnail_url,status,source,confidence,viewers,started_at,last_seen_at,ended_at,metadata) VALUES(?,?,?,?,?,?,'unconfirmed','automatic',?,?,?,UTC_TIMESTAMP(),NULL,?) ON DUPLICATE KEY UPDATE title=VALUES(title),url=VALUES(url),thumbnail_url=VALUES(thumbnail_url),status='unconfirmed',confidence=VALUES(confidence),viewers=COALESCE(VALUES(viewers),viewers),last_seen_at=UTC_TIMESTAMP(),metadata=VALUES(metadata),ended_at=NULL");
    $stmt->execute([$key,(string)$live['profileId'],(string)$live['platform'],$safeTitle,(string)$live['url'],(string)($live['thumbnail']??''),(int)$live['confidence'],$live['viewers']??null,$live['startedAt']??null,json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}
/** IONOS ne voit pas TikTok : HTML/403/timeout ne prouvent ni un live ni une fin. */
function p50_live_v4_tiktok_probe_is_inconclusive(string $error): bool {
    $error=strtolower(trim($error));
    return in_array($error,[
        'tiktok_no_live_signal',
        'tiktok_no_live_signal_while_live',
        'tiktok_api_failed',
        'tiktok_api_failed_html_ended',
        'tiktok_embed_uninformative',
        'tiktok_blocked_or_challenged',
        'tiktok_confirmation_incomplete',
    ],true);
}

/** IONOS HTML sans JSON live ne doit pas clôturer un direct encore confirmé (webcast GitHub). */
function p50_live_v4_should_end_from_probe(string $previousState, array $result): bool {
    $state=(string)($result['state']??'');
    if($state==='replay')return true;
    if($state!=='offline')return false;
    $error=(string)($result['error']??'');
    if(p50_live_v4_tiktok_probe_is_inconclusive($error))return false;
    return true;
}
function p50_live_v4_health_update(array $source,array $result): array {
    $state=(string)($result['state']??'unknown');$profileId=(string)$source['profile_id'];$platform=(string)$source['platform'];$url=(string)$source['url'];$urlHash=hash('sha256',strtolower(rtrim($url,'/')));
    $stmt=db()->prepare('SELECT url_hash,last_state,last_live_at,consecutive_offline,consecutive_unknown FROM p50_live_source_health WHERE profile_id=? AND platform=? LIMIT 1');$stmt->execute([$profileId,$platform]);$previous=$stmt->fetch()?:[];
    $previousState=strtolower(trim((string)($previous['last_state']??'never_checked')));
    if($state==='offline'&&!p50_live_v4_should_end_from_probe($previousState,$result))$state='unknown';
    $sameUrl=(string)($previous['url_hash']??'')===$urlHash;$offline=$state==='offline'?($sameUrl?(int)($previous['consecutive_offline']??0):0)+1:0;$unknown=$state==='unknown'?($sameUrl?(int)($previous['consecutive_unknown']??0)+1:1):0;$metadata=['confidence'=>(int)($result['confidence']??0),'probes'=>$result['probes']??[],'evidence'=>$result['evidence']??[]];
    // Un unknown IONOS (timeout / embed) ne doit pas cacher un LIVE encore frais : last_seen reste la fenêtre publique.
    $storedState=($state==='unknown'&&$previousState==='live')?'live':$state;
    $upsert=db()->prepare("INSERT INTO p50_live_source_health(profile_id,platform,url_hash,official_url,last_state,consecutive_offline,consecutive_unknown,last_checked_at,last_live_at,response_ms,last_error,metadata) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),IF(?='live',UTC_TIMESTAMP(),NULL),?,?,?) ON DUPLICATE KEY UPDATE url_hash=VALUES(url_hash),official_url=VALUES(official_url),last_state=VALUES(last_state),consecutive_offline=VALUES(consecutive_offline),consecutive_unknown=VALUES(consecutive_unknown),last_checked_at=UTC_TIMESTAMP(),last_live_at=IF(?='live',UTC_TIMESTAMP(),last_live_at),response_ms=VALUES(response_ms),last_error=VALUES(last_error),metadata=VALUES(metadata)");
    $upsert->execute([$profileId,$platform,$urlHash,$url,$storedState,$offline,$unknown,$state,(int)($result['responseMs']??0),substr((string)($result['error']??''),0,255),json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$state]);
    return ['previousState'=>$previousState==='never_checked'?'never_checked':(string)($previous['last_state']??'never_checked'),'previousLiveAt'=>$previous['last_live_at']??null,'offline'=>$offline,'unknown'=>$unknown];
}
function p50_live_v4_mark_ended(string $profileId,string $platform,string $reason='offline',?array $replay=null): void {
    if(strcasecmp($platform,'TikTok')===0 && $reason!=='replay' && $reason!=='tiktok_live_ended' && $reason!=='manually_dismissed'){
        return;
    }
    $metadata=json_encode(['endReason'=>$reason,'replay'=>$replay,'endedObservedAt'=>gmdate(DATE_ATOM)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $stmt=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()),metadata=JSON_MERGE_PATCH(COALESCE(metadata,'{}'),?) WHERE profile_id=? AND platform=? AND source='automatic' AND status IN ('live','unconfirmed')");$stmt->execute([$metadata,$profileId,$platform]);
}
function p50_live_v4_active_rows(): array {
    p50_live_v4_ensure_dismissals();
    foreach(P50_LIVE_V4_FALSE_POSITIVE_TIKTOK_PROFILES as $profileId){
        $stmt=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()),metadata=JSON_SET(COALESCE(metadata,'{}'),'$.endReason','known_false_positive') WHERE profile_id=? AND platform='TikTok' AND source='automatic' AND status IN ('live','unconfirmed')");
        $stmt->execute([$profileId]);
    }
    foreach(P50_LIVE_V4_FALSE_POSITIVE_YOUTUBE_PROFILES as $profileId){
        $stmt=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()),metadata=JSON_SET(COALESCE(metadata,'{}'),'$.endReason','known_false_positive') WHERE profile_id=? AND platform='YouTube' AND source='automatic' AND status IN ('live','unconfirmed')");
        $stmt->execute([$profileId]);
    }
    db()->exec("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()) WHERE source='automatic' AND status='unconfirmed' AND platform NOT IN ('TikTok','YouTube') AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)");
    db()->exec("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()) WHERE source='meta_authorized' AND status='live' AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE)");
    // TikTok/YouTube : un live retiré uniquement pour « trop vieux » redevient public.
    db()->exec("UPDATE p50_live_streams SET status='live',ended_at=NULL,metadata=JSON_REMOVE(COALESCE(metadata,'{}'),'$.withdrawalReason') WHERE source='automatic' AND status='unconfirmed' AND platform IN ('TikTok','YouTube') AND ended_at IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata,'{}'),'$.withdrawalReason'))='confirmation_grace_expired'");
    // Replay → retrait. TikTok offline HTML IONOS n’est pas une preuve de fin.
    db()->exec("UPDATE p50_live_streams s JOIN p50_live_source_health h ON h.profile_id=s.profile_id AND h.platform=s.platform SET s.status='unconfirmed',s.metadata=JSON_SET(COALESCE(s.metadata,'{}'),'$.withdrawalReason','latest_probe_offline') WHERE s.source='automatic' AND s.status='live' AND ((s.platform IN ('TikTok','YouTube') AND h.last_state='replay') OR (s.platform NOT IN ('TikTok','YouTube') AND h.last_state IN ('offline','replay')))");
    // Grâce d’âge : Instagram / Facebook seulement (minutes=0 = jamais).
    foreach(p50_live_v4_reconfirm_grace_map() as $platform=>$configured){
        $minutes=(int)$configured;
        if($minutes<=0||p50_live_v4_detected_live_has_no_time_limit($platform))continue;
        $minutes=max(1,$minutes);
        $stmt=db()->prepare("UPDATE p50_live_streams SET status='unconfirmed',metadata=JSON_SET(COALESCE(metadata,'{}'),'$.withdrawalReason','confirmation_grace_expired') WHERE source='automatic' AND status='live' AND platform=? AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$minutes} MINUTE)");
        $stmt->execute([$platform]);
    }
    // TikTok/YouTube : status=live suffit (unknown IONOS ne masque pas). IG/FB : fenêtre.
    $conditions=[];$params=[];
    foreach(p50_live_v4_trust_seconds_map() as $platform=>$seconds){
        $seconds=max(0,(int)$seconds);
        if(p50_live_v4_detected_live_has_no_time_limit($platform)||$seconds<=0){
            $conditions[]="(s.source='automatic' AND s.platform=? AND (h.last_state IS NULL OR h.last_state<>'replay'))";
            $params[]=$platform;
            continue;
        }
        $seconds=max(30,$seconds);
        $conditions[]="(s.source='automatic' AND s.platform=? AND h.last_state='live' AND s.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$seconds} SECOND) AND h.last_checked_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$seconds} SECOND))";
        $params[]=$platform;
    }
    $conditions[]="(s.source='meta_authorized' AND s.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE))";
    $stmt=db()->prepare("SELECT s.*,h.last_state,h.last_checked_at,h.last_live_at,h.last_error,h.response_ms FROM p50_live_streams s LEFT JOIN p50_live_source_health h ON h.profile_id=s.profile_id AND h.platform=s.platform LEFT JOIN p50_live_dismissals d ON d.stream_key=s.stream_key AND d.dismissed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) WHERE d.stream_key IS NULL AND s.source IN ('automatic','meta_authorized') AND s.status='live' AND (".implode(' OR ',$conditions).") ORDER BY (s.source='meta_authorized') DESC,s.last_seen_at DESC");
    $stmt->execute($params);$out=[];
    foreach($stmt->fetchAll() as $row){
        if(p50_live_v4_is_profile_suppressed((string)$row['profile_id'],(string)$row['platform']))continue;
        $source=(string)$row['source'];
        $meta=json_decode((string)($row['metadata']??''),true);
        if(!is_array($meta))$meta=[];
        $handle=trim((string)($meta['handle']??''));
        if($handle!==''&&!str_starts_with($handle,'@'))$handle='@'.$handle;
        $platform=(string)$row['platform'];
        $profileId=p50_live_v4_canonical_profile_id((string)$row['profile_id'],$platform,$handle!==''?$handle:(string)$row['url']);
        $candidate=[
            'id'=>($source==='meta_authorized'?'meta_':'auto_').substr((string)$row['stream_key'],0,18),
            'profileId'=>$profileId,'platform'=>$platform,'title'=>(string)$row['title'],
            'url'=>(string)$row['url'],'thumbnail'=>(string)($row['thumbnail_url']??''),'status'=>'live','source'=>$source,
            'confidence'=>(int)$row['confidence'],'viewers'=>$row['viewers']!==null?(int)$row['viewers']:null,
            'startedAt'=>p50_live_v4_iso($row['started_at']??null),'lastSeenAt'=>p50_live_v4_iso($row['last_seen_at']??null),
            'lastConfirmedAt'=>p50_live_v4_iso($row['last_seen_at']??null),
            'lastCheckState'=>$source==='meta_authorized'?'live':(string)($row['last_state']??'unknown'),'endsAt'=>null,
            'last_state'=>$source==='meta_authorized'?'live':(string)($row['last_state']??'unknown'),
            'last_seen_at'=>(string)($row['last_seen_at']??''),
            'roomId'=>trim((string)($meta['roomId']??'')),
            'videoId'=>trim((string)($meta['videoId']??'')),
            'handle'=>$handle,
            'metadata'=>[
                'roomId'=>trim((string)($meta['roomId']??'')),
                'videoId'=>trim((string)($meta['videoId']??'')),
                'handle'=>$handle,
                'probe'=>(string)($meta['probe']??''),
            ],
        ];
        if(!p50_live_v4_is_publicly_fresh($candidate))continue;
        if(p50_live_v4_detected_live_has_no_time_limit($platform,$source)){
            $candidate['lastCheckState']='live';
            $candidate['lastConfirmedAt']=gmdate(DATE_ATOM);
        }
        unset($candidate['last_state'],$candidate['last_seen_at']);
        $candidate['trust']=['gate'=>P50_LIVE_V4_TRUST_REVISION,'maxAgeSeconds'=>p50_live_v4_public_max_age((string)$candidate['platform']),'fresh'=>true];
        $out[]=$candidate;
    }
    return $out;
}
function p50_live_v4_manual_streams(array $state): array {$now=time();$out=[];foreach((array)($state['liveStreams']??[]) as $live){if(!is_array($live)||($live['source']??'')!=='manual'||str_starts_with((string)($live['id']??''),'auto_')||($live['status']??'')!=='live'||empty($live['profileId'])||!p50_public_http_url((string)($live['url']??'')))continue;$end=strtotime((string)($live['endsAt']??''));if($end===false||$end<=$now)continue;$live['id']=(string)($live['id']??('manual_'.substr(hash('sha256',(string)$live['url']),0,16)));$live['source']='manual';$out[]=$live;}return $out;}
function p50_live_v4_public_identity_keys(array $stream): array {
    $platform=strtolower(trim((string)($stream['platform']??'')));
    $keys=[];
    $event=strtolower(p50_live_v4_event_identity($stream));
    if($event!=='')$keys[]=$platform.'|event:'.$event;
    $handle=strtolower(trim((string)($stream['handle']??''),'@'));
    if($handle===''){
        $meta=is_array($stream['metadata']??null)?$stream['metadata']:[];
        $handle=strtolower(trim((string)($meta['handle']??''),'@'));
    }
    if($handle!=='')$keys[]=$platform.'|handle:'.$handle;
    $url=strtolower(rtrim((string)($stream['url']??''),'/'));
    if($url!=='')$keys[]=$platform.'|url:'.$url;
    $profile=strtolower(trim((string)($stream['profileId']??'')));
    if($profile!=='')$keys[]=$platform.'|profile:'.$profile;
    return $keys;
}
function p50_live_v4_dedup(array $automatic,array $manual): array {
    usort($automatic,static fn($a,$b)=>(($b['source']??'')==='meta_authorized')<=>(($a['source']??'')==='meta_authorized'));
    $normalized=[];
    foreach(array_merge($automatic,$manual) as $stream){
        if(!is_array($stream))continue;
        $platform=(string)($stream['platform']??'');
        $handle=(string)($stream['handle']??'');
        $url=(string)($stream['url']??'');
        $id=(string)($stream['profileId']??'');
        $canonical=p50_live_v4_canonical_profile_id($id,$platform,$handle!==''?$handle:$url);
        if($canonical!==$id){
            $stream['profileId']=$canonical;
            if($canonical==='census-samuella-kouassi'){
                $stream['profileName']='Samuella Kouassi';
                if(trim($handle)==='')$stream['handle']='@samuellakouassiofficiel';
            }
            if($canonical==='census-bb-sans-os-de-man'){
                $stream['profileName']='BB Sans Os de Man';
                if(trim($handle)==='')$stream['handle']='@bebe.sans.os.de.m';
            }
            if($canonical==='hassan'){
                $stream['profileName']='Hassan Hayek';
                if(trim($handle)==='')$stream['handle']='@hassanhayekofficiel';
            }
            if($canonical==='census-le-grand-bicongo'){
                $stream['profileName']='Le grand Bicongo';
                if(trim($handle)==='')$stream['handle']='@legrandbicongo';
            }
            if($canonical==='census-chocolat-show-officiel'){
                $stream['profileName']='Chocolat show officiel';
                if(trim($handle)==='')$stream['handle']='@chocolat.show.officiel';
            }
            if($canonical==='census-la-legende'){
                $stream['profileName']='La légende';
                if(trim($handle)==='')$stream['handle']='@lalegende777';
            }
            if($canonical==='census-willway-jordan-officiel'){
                $stream['profileName']='Willway Jordan officiel';
                if(trim($handle)==='')$stream['handle']='@jack.carter39';
            }
            if($canonical==='census-guyguy-le-grouilleur-de-bologne'){
                $stream['profileName']='Guyguy le grouilleur de Bologne';
                if(trim($handle)==='')$stream['handle']='@guyguylegrouilleur07';
            }
            if($canonical==='census-souley-de-paris'){
                $stream['profileName']='Souley de Paris';
                if(trim($handle)==='')$stream['handle']='@souleydeparis';
            }
            if($canonical==='census-billal'){
                $stream['profileName']='Billal';
                if(trim($handle)==='')$stream['handle']='@billal_off2';
            }
            if($canonical==='census-ange-boli'){
                $stream['profileName']='Ange Boli';
                if(trim($handle)==='')$stream['handle']='@angeboli7';
            }
        }
        $normalized[]=$stream;
    }
    $canonicalIds=array_flip(p50_live_v4_tiktok_handle_canonicals());
    $named=static function(array $stream) use($canonicalIds): int {
        $id=(string)($stream['profileId']??'');
        if(isset($canonicalIds[$id]))return 2;
        $name=trim((string)($stream['profileName']??''));
        return ($name!==''&&strcasecmp($name,'Influenceur')!==0)?1:0;
    };
    usort($normalized,static function(array $a,array $b) use($named): int {
        $rank=$named($b)<=>$named($a);
        return $rank!==0?$rank:strcmp((string)($b['startedAt']??$b['lastSeenAt']??''),(string)($a['startedAt']??$a['lastSeenAt']??''));
    });
    $out=[];$seen=[];
    foreach($normalized as $stream){
        $keys=p50_live_v4_public_identity_keys($stream);
        if(!$keys)continue;
        $duplicate=false;
        foreach($keys as $key){if(isset($seen[$key])){$duplicate=true;break;}}
        if($duplicate)continue;
        foreach($keys as $key)$seen[$key]=true;
        $out[]=$stream;
    }
    usort($out,static fn($a,$b)=>strcmp((string)($b['startedAt']??$b['lastSeenAt']??''),(string)($a['startedAt']??$a['lastSeenAt']??'')));
    return $out;
}
function p50_live_v4_health_summary(array $sources,array $activeAutomatic): array {
    $summary=[];foreach(P50_LIVE_V4_PLATFORMS as $platform)$summary[$platform]=['live'=>0,'offline'=>0,'replay'=>0,'unknown'=>0,'unconfirmed'=>0,'never_checked'=>0];$official=[];$active=[];$health=[];$streams=[];
    foreach($sources as $source){$key=strtolower((string)$source['platform']).'|'.trim((string)$source['profile_id']);$official[$key]=['platform'=>(string)$source['platform']];}foreach($activeAutomatic as $stream){$key=strtolower((string)$stream['platform']).'|'.trim((string)$stream['profileId']);$active[$key]=true;}
    try{foreach(db()->query('SELECT profile_id,platform,last_state FROM p50_live_source_health')->fetchAll() as $row){$key=strtolower((string)$row['platform']).'|'.trim((string)$row['profile_id']);if(isset($official[$key]))$health[$key]=(string)$row['last_state'];}foreach(db()->query("SELECT profile_id,platform,status FROM p50_live_streams WHERE source IN ('automatic','meta_authorized') ORDER BY (source='meta_authorized') DESC,profile_id,platform,last_seen_at DESC,stream_key DESC")->fetchAll() as $row){$key=strtolower((string)$row['platform']).'|'.trim((string)$row['profile_id']);if(isset($official[$key])&&!isset($streams[$key]))$streams[$key]=(string)$row['status'];}}catch(Throwable){}
    foreach($official as $key=>$source){
        $state=$health[$key]??null;
        if(isset($active[$key]))$category='live';
        elseif(($streams[$key]??'')==='unconfirmed'||$state==='probable')$category='unconfirmed';
        elseif($state==='live')$category='unconfirmed'; // live en health mais pas encore public (preuve/filtre)
        elseif($state==='replay')$category='replay';
        elseif($state==='offline')$category='offline';
        elseif($state==='unknown')$category='unknown';
        else $category='never_checked';
        $summary[$source['platform']][$category]++;
    }
    return $summary;
}
function p50_live_v4_cycle_id(): string {$raw=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($_GET['cycle']??''))?:'';return $raw!==''?substr($raw,0,64):('cycle_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(4)));}
function p50_live_v4_cycle_key(string $cycleId): string {return 'live_radar_v4_cycle_'.substr(hash('sha256',$cycleId),0,24);}
