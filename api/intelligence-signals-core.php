<?php
declare(strict_types=1);

require_once __DIR__.'/intelligence-core.php';
require_once __DIR__.'/intelligence-dashboard-v2.php';

const P50_INTELLIGENCE_SIGNALS_V1='PASS50-INTELLIGENCE-SIGNALS-V1.1';

function p50_is_ensure_schema(): void {
    static $done=false;
    if($done)return;
    p50_intelligence_ensure_schema();
    db()->exec("CREATE TABLE IF NOT EXISTS p50_signal_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        signal_key CHAR(64) CHARACTER SET ascii NOT NULL,
        profile_id VARCHAR(100) NOT NULL,
        source_type VARCHAR(32) NOT NULL,
        source_id VARCHAR(190) NOT NULL DEFAULT '',
        event_type VARCHAR(48) NOT NULL DEFAULT 'signal',
        title VARCHAR(255) NOT NULL,
        platforms_json LONGTEXT NOT NULL,
        evidence_url TEXT NULL,
        evidence_json LONGTEXT NULL,
        signal_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        confidence_level VARCHAR(16) NOT NULL DEFAULT 'faible',
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        occurred_at DATETIME NOT NULL,
        first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,
        reviewed_by CHAR(36) NULL,
        UNIQUE KEY uq_p50_signal_key(signal_key),
        INDEX idx_p50_signal_profile_date(profile_id,occurred_at),
        INDEX idx_p50_signal_status_date(status,occurred_at),
        INDEX idx_p50_signal_score(signal_score,occurred_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done=true;
}

function p50_is_json(mixed $value,array $fallback=[]): array {
    if(is_array($value))return $value;
    $decoded=json_decode((string)$value,true);
    return is_array($decoded)?$decoded:$fallback;
}

function p50_is_platforms(mixed $value): array {
    $platforms=p50_is_json($value,[]);$out=[];
    foreach($platforms as $platform){
        $platform=trim((string)$platform);
        if($platform!=='')$out[$platform]=true;
    }
    return array_keys($out);
}

function p50_is_confidence_label(int $confidence): string {
    if($confidence>=80)return 'élevée';
    if($confidence>=55)return 'moyenne';
    return 'faible';
}

function p50_is_timestamp(mixed $value): string {
    if(is_numeric($value)){
        $timestamp=(float)$value;
        if($timestamp>20000000000)$timestamp/=1000;
        if($timestamp>0)return gmdate('Y-m-d H:i:s',(int)$timestamp);
    }
    $timestamp=strtotime((string)$value);
    return $timestamp===false?p50_de_now():gmdate('Y-m-d H:i:s',$timestamp);
}

function p50_is_upsert(array $signal): int {
    p50_is_ensure_schema();
    $key=hash('sha256',(string)($signal['key']??''));
    $status=(string)($signal['status']??'pending');
    if(!in_array($status,['pending','validated','rejected'],true))$status='pending';
    $stmt=db()->prepare("INSERT INTO p50_signal_events(
        signal_key,profile_id,source_type,source_id,event_type,title,platforms_json,evidence_url,evidence_json,
        signal_score,confidence_level,status,occurred_at,first_seen_at,last_seen_at
    ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
    ON DUPLICATE KEY UPDATE
        title=VALUES(title),platforms_json=VALUES(platforms_json),evidence_url=VALUES(evidence_url),
        evidence_json=VALUES(evidence_json),signal_score=VALUES(signal_score),confidence_level=VALUES(confidence_level),
        status=CASE WHEN p50_signal_events.status IN ('validated','rejected') THEN p50_signal_events.status ELSE VALUES(status) END,
        occurred_at=VALUES(occurred_at),last_seen_at=UTC_TIMESTAMP()");
    $stmt->execute([
        $key,(string)$signal['profileId'],(string)$signal['sourceType'],(string)($signal['sourceId']??''),
        (string)($signal['eventType']??'signal'),mb_substr(trim((string)$signal['title']),0,255),
        json_encode(array_values((array)($signal['platforms']??[])),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        trim((string)($signal['evidenceUrl']??''))?:null,
        json_encode((array)($signal['evidence']??[]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        max(0,min(100,(int)($signal['signalScore']??0))),
        (string)($signal['confidenceLevel']??'faible'),$status,p50_is_timestamp($signal['occurredAt']??null),
    ]);
    return $stmt->rowCount();
}

function p50_is_import_state_signals(array $currentIds): int {
    $state=p50_de_load_public_state();$count=0;
    foreach((array)($state['signals']??[]) as $signal){
        if(!is_array($signal))continue;
        $profileId=trim((string)($signal['profileId']??''));
        if($profileId===''||!isset($currentIds[$profileId]))continue;
        $platforms=array_values(array_filter(array_map('strval',(array)($signal['platforms']??[]))));
        $confidence=(string)($signal['confidence']??'faible');
        $score=$confidence==='élevée'?82:($confidence==='moyenne'?64:42);
        $status=(string)($signal['status']??'pending');
        p50_is_upsert([
            'key'=>'manual-state|'.(string)($signal['id']??hash('sha256',json_encode($signal))).'|'.$profileId,
            'profileId'=>$profileId,'sourceType'=>'manual_state','sourceId'=>(string)($signal['id']??''),
            'eventType'=>'manual','title'=>(string)($signal['title']??'Signal manuel'),
            'platforms'=>$platforms,'evidenceUrl'=>(string)($signal['url']??''),'evidence'=>$signal,
            'signalScore'=>$score,'confidenceLevel'=>$confidence,'status'=>$status,
            'occurredAt'=>$signal['createdAt']??p50_de_now(),
        ]);
        $count++;
    }
    return $count;
}

function p50_is_metric_volume(array $metrics): float {
    $volume=0.0;
    foreach(['views','likes','comments','shares','reposts','engagement'] as $metric){
        $value=$metrics[$metric]??0;
        if(is_numeric($value))$volume+=max(0.0,(float)$value);
    }
    return $volume;
}

function p50_is_activity_score(array $row,array $metrics): int {
    $occurred=strtotime((string)($row['occurred_at']??''));
    $ageHours=$occurred===false?168:max(0,(time()-$occurred)/3600);
    $recency=$ageHours<=6?28:($ageHours<=24?22:($ageHours<=72?13:6));
    $confidence=max(0,min(100,(int)($row['confidence']??0)));
    $confidencePoints=(int)round($confidence*.28);
    $volume=p50_is_metric_volume($metrics);
    $volumePoints=$volume>0?(int)min(34,round(log10($volume+1)*8)):0;
    $statusBonus=in_array((string)($row['status']??''),['validated','verified','published'],true)?10:0;
    return max(0,min(100,$recency+$confidencePoints+$volumePoints+$statusBonus));
}

function p50_is_import_activity_events(array $currentIds,int $days=7): int {
    if(!$currentIds)return 0;
    $days=max(1,min(30,$days));
    $stmt=db()->query("SELECT id,profile_id,platform,event_type,title,url,url_hash,published_at,metrics,confidence,status,collected_at,
        COALESCE(published_at,collected_at) occurred_at
        FROM p50_activity_events
        WHERE COALESCE(published_at,collected_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL $days DAY)
        ORDER BY COALESCE(published_at,collected_at) DESC,id DESC");
    $count=0;
    foreach($stmt->fetchAll() as $row){
        $profileId=(string)$row['profile_id'];
        if(!isset($currentIds[$profileId]))continue;
        $metrics=p50_is_json($row['metrics']??null,[]);
        $confidence=max(0,min(100,(int)($row['confidence']??0)));
        $status=(string)$row['status']==='rejected'?'rejected':'pending';
        p50_is_upsert([
            'key'=>'activity|'.$profileId.'|'.((string)($row['url_hash']??'')?:$row['id']),
            'profileId'=>$profileId,'sourceType'=>'activity','sourceId'=>(string)$row['id'],
            'eventType'=>(string)$row['event_type'],'title'=>(string)$row['title'],
            'platforms'=>[(string)$row['platform']],'evidenceUrl'=>(string)$row['url'],
            'evidence'=>['metrics'=>$metrics,'activityStatus'=>$row['status']],
            'signalScore'=>p50_is_activity_score($row,$metrics),'confidenceLevel'=>p50_is_confidence_label($confidence),
            'status'=>$status,'occurredAt'=>$row['occurred_at'],
        ]);
        $count++;
    }
    return $count;
}

function p50_is_table_exists(string $table): bool {
    $name=preg_replace('/[^A-Za-z0-9_]/','',$table);
    if($name==='')return false;
    try{
        $stmt=db()->query('SHOW TABLES LIKE '.db()->quote($name));
        return $stmt!==false&&(bool)$stmt->fetchColumn();
    }catch(Throwable){
        return false;
    }
}

function p50_is_live_signal_score(array $row,bool $isLive): int {
    $occurred=strtotime((string)($row['occurred_at']??''));
    $ageHours=$occurred===false?168.0:max(0.0,(time()-$occurred)/3600);
    $recency=$isLive?42:($ageHours<=6?34:($ageHours<=24?26:($ageHours<=72?16:8)));
    $confidence=max(0,min(100,(int)($row['confidence']??0)));
    $viewers=max(0,(int)($row['viewers']??0));
    $viewerPoints=$viewers>0?(int)min(18,round(log10($viewers+1)*6)):0;
    return max(0,min(100,$recency+(int)round($confidence*.20)+$viewerPoints));
}

function p50_is_import_live_streams(array $currentIds,int $days=7): int {
    if(!$currentIds)return 0;
    $days=max(1,min(30,$days));
    $count=0;
    if(p50_is_table_exists('p50_live_streams')){
        $stmt=db()->query("SELECT stream_key,profile_id,platform,title,url,status,source,confidence,viewers,
            COALESCE(started_at,last_seen_at) occurred_at,last_seen_at,ended_at
            FROM p50_live_streams
            WHERE COALESCE(ended_at,last_seen_at,started_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL $days DAY)
            ORDER BY last_seen_at DESC,stream_key DESC LIMIT 2000");
        foreach($stmt->fetchAll() as $row){
            $profileId=(string)($row['profile_id']??'');
            if($profileId===''||!isset($currentIds[$profileId]))continue;
            $platform=trim((string)($row['platform']??''));
            $isLive=(string)($row['status']??'')==='live';
            $title=trim((string)($row['title']??''));
            if($title==='')$title=$platform!==''?($platform.' · live radar'):'Live radar';
            p50_is_upsert([
                'key'=>'live-radar|'.((string)($row['stream_key']??'')?:$profileId.'|'.$platform.'|'.(string)($row['url']??'')),
                'profileId'=>$profileId,'sourceType'=>'live_radar','sourceId'=>(string)($row['stream_key']??''),
                'eventType'=>'live','title'=>$title,'platforms'=>$platform!==''?[$platform]:[],
                'evidenceUrl'=>(string)($row['url']??''),
                'evidence'=>['status'=>$row['status']??'','source'=>$row['source']??'','viewers'=>$row['viewers']??null],
                'signalScore'=>p50_is_live_signal_score($row,$isLive),
                'confidenceLevel'=>p50_is_confidence_label((int)($row['confidence']??0)),
                'status'=>'validated','occurredAt'=>$row['occurred_at']??$row['last_seen_at']??null,
            ]);
            $count++;
        }
    }
    $state=p50_de_load_public_state();
    foreach((array)($state['liveStreams']??[]) as $live){
        if(!is_array($live))continue;
        $profileId=trim((string)($live['profileId']??''));
        if($profileId===''||!isset($currentIds[$profileId]))continue;
        $platform=trim((string)($live['platform']??''));
        $url=trim((string)($live['url']??''));
        $occurred=$live['lastSeenAt']??$live['lastConfirmedAt']??$live['startedAt']??$live['endsAt']??null;
        $isLive=($live['status']??'')==='live';
        $title=trim((string)($live['title']??''));
        if($title==='')$title=$platform!==''?($platform.' · radar public'):'Live radar';
        p50_is_upsert([
            'key'=>'live-state|'.$profileId.'|'.$platform.'|'.($url!==''?hash('sha256',$url):(string)($live['id']??'')),
            'profileId'=>$profileId,'sourceType'=>'live_state','sourceId'=>(string)($live['id']??''),
            'eventType'=>'live','title'=>$title,'platforms'=>$platform!==''?[$platform]:[],
            'evidenceUrl'=>$url,'evidence'=>['status'=>$live['status']??'','source'=>$live['source']??''],
            'signalScore'=>p50_is_live_signal_score(['occurred_at'=>$occurred,'confidence'=>$live['confidence']??70,'viewers'=>$live['viewers']??0],$isLive),
            'confidenceLevel'=>p50_is_confidence_label((int)($live['confidence']??70)),
            'status'=>'validated','occurredAt'=>$occurred,
        ]);
        $count++;
    }
    return $count;
}

function p50_is_public_ranking_index(array $state,string $period='24H'): array {
    $wanted=['2H','24H','48H','7J','15J'];
    if(!in_array($period,$wanted,true))$period='24H';
    $scored=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile))continue;
        $profileId=trim((string)($profile['id']??''));
        if($profileId==='')continue;
        if(array_key_exists('alive',$profile)&&empty($profile['alive']))continue;
        $score=$profile['scores'][$period]??null;
        if(!is_numeric($score)||(float)$score<=0)continue;
        $scored[]=['profileId'=>$profileId,'name'=>(string)($profile['name']??$profileId),'score'=>(float)$score,'period'=>$period];
    }
    usort($scored,static fn($a,$b)=>$b['score']<=>$a['score']?:strcmp($a['name'],$b['name'])?:strcmp($a['profileId'],$b['profileId']));
    $index=[];
    foreach($scored as $position=>$row)$index[$row['profileId']]=$row+['rank'=>$position+1];
    return $index;
}

function p50_is_profile_official_platforms(array $profile): array {
    $out=[];
    foreach((array)($profile['links']??[]) as $platform=>$url){
        if(trim((string)$url)==='')continue;
        $name=trim((string)$platform);
        if($name!=='')$out[$name]=true;
    }
    foreach((array)($profile['platforms']??[]) as $platform){
        $name=trim((string)$platform);
        if($name!=='')$out[$name]=true;
    }
    return array_keys($out);
}

function p50_is_official_platforms_map(array $state): array {
    $map=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id']))continue;
        $id=(string)$profile['id'];
        foreach(p50_is_profile_official_platforms($profile) as $platform)$map[$id][$platform]=true;
    }
    if(!p50_is_table_exists('p50_social_links')){
        foreach($map as $id=>$platforms)$map[$id]=array_keys($platforms);
        return $map;
    }
    try{
        $stmt=db()->query("SELECT profile_id,platform FROM p50_social_links WHERE status IN ('verified','ok','manual_verified','owner_verified') AND platform<>'' LIMIT 4000");
        foreach($stmt->fetchAll() as $row){
            $id=(string)($row['profile_id']??'');
            $platform=trim((string)($row['platform']??''));
            if($id===''||$platform==='')continue;
            $map[$id][$platform]=true;
        }
    }catch(Throwable){}
    foreach($map as $id=>$platforms)$map[$id]=array_keys($platforms);
    return $map;
}

function p50_is_reset_unreviewed_activity_signals(): int {
    if(!p50_is_table_exists('p50_signal_events'))return 0;
    try{
        return (int)db()->exec("UPDATE p50_signal_events SET status='pending' WHERE source_type='activity' AND reviewed_at IS NULL AND status='validated'");
    }catch(Throwable){
        return 0;
    }
}

function p50_is_import_all(): array {
    p50_de_sync_registry_from_state();
    $currentIds=p50_intelligence_current_profile_ids();
    $deactivated=p50_intelligence_sync_removed_profiles($currentIds);
    $activityImported=p50_is_import_activity_events($currentIds,7);
    $reset=p50_is_reset_unreviewed_activity_signals();
    return [
        'currentIds'=>$currentIds,'deactivated'=>$deactivated,
        'manualImported'=>p50_is_import_state_signals($currentIds),
        'activityImported'=>$activityImported,
        'liveImported'=>p50_is_import_live_streams($currentIds,7),
        'activityAutoValidatedReset'=>$reset,
    ];
}

function p50_is_latest_intelligence(array $currentIds): array {
    if(!$currentIds)return [];
    $rows=db()->query("SELECT s.*,r.public_name FROM p50_intelligence_snapshots s
        JOIN (SELECT profile_id,MAX(id) latest_id FROM p50_intelligence_snapshots GROUP BY profile_id) latest ON latest.latest_id=s.id
        JOIN p50_profile_registry r ON r.profile_id=s.profile_id AND r.alive=1")->fetchAll();
    $state=p50_de_load_public_state();$photos=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(is_array($profile)&&!empty($profile['id']))$photos[(string)$profile['id']]=(string)($profile['photoUrl']??$profile['photoCandidateUrl']??'');
    }
    $map=[];
    foreach($rows as $row){
        $profileId=(string)$row['profile_id'];if(!isset($currentIds[$profileId]))continue;
        $metrics=p50_is_json($row['metrics_json']??null,[]);
        $item=[
            'profileId'=>$profileId,'name'=>(string)$row['public_name'],'photo'=>$photos[$profileId]??'',
            'growthIndex'=>(int)$row['growth_index'],'buzzIndex'=>(int)$row['buzz_index'],
            'confidenceLevel'=>(string)$row['confidence_level'],'globalVariation'=>(float)($metrics['globalVariation']??0),
            'mainVariation'=>(string)($metrics['mainVariationLabel']??'Données insuffisantes'),
            'comparisonStatus'=>(string)($metrics['comparisonStatus']??'insufficient_history'),
            'recentData'=>(bool)($metrics['recentData']??false),'mainSignal'=>(string)$row['main_signal'],
            'explanation'=>(string)($metrics['explanation']??'Données insuffisantes.'),
            'periodStart'=>gmdate(DATE_ATOM,strtotime((string)$row['period_start'])),
            'periodEnd'=>gmdate(DATE_ATOM,strtotime((string)$row['period_end'])),
        ];
        $map[$profileId]=p50_intelligence_sanitize_item($item);
    }
    return $map;
}

function p50_is_signal_rows(array $currentIds,int $days=7): array {
    if(!$currentIds)return [];
    $days=max(1,min(30,$days));
    $stmt=db()->query("SELECT s.*,r.public_name FROM p50_signal_events s
        JOIN p50_profile_registry r ON r.profile_id=s.profile_id AND r.alive=1
        WHERE s.occurred_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL $days DAY)
        ORDER BY s.occurred_at DESC,s.signal_score DESC,s.id DESC");
    $rows=[];
    foreach($stmt->fetchAll() as $row){
        $profileId=(string)$row['profile_id'];if(!isset($currentIds[$profileId]))continue;
        $rows[]=[
            'id'=>(int)$row['id'],'profileId'=>$profileId,'name'=>(string)$row['public_name'],
            'sourceType'=>(string)$row['source_type'],'eventType'=>(string)$row['event_type'],
            'title'=>(string)$row['title'],'platforms'=>p50_is_platforms($row['platforms_json']),
            'evidenceUrl'=>(string)($row['evidence_url']??''),'signalScore'=>(int)$row['signal_score'],
            'confidenceLevel'=>(string)$row['confidence_level'],'status'=>(string)$row['status'],
            'occurredAt'=>gmdate(DATE_ATOM,strtotime((string)$row['occurred_at'])),
        ];
    }
    return $rows;
}

function p50_is_profile_aggregate(array $profile,array $signals,array $context=[]): array {
    $publicRank=max(0,(int)($context['publicRank']??0));
    $publicScore=max(0.0,(float)($context['publicScore']??0));
    $publicPeriod=(string)($context['publicPeriod']??'');
    $active=array_values(array_filter($signals,static fn($signal)=>$signal['status']!=='rejected'));
    $hasLive=false;
    foreach($active as $signal){
        $source=(string)($signal['sourceType']??'');
        if($source==='live_radar'||$source==='live_state'||($signal['eventType']??'')==='live')$hasLive=true;
    }
    $ranked=$publicScore>0||$publicRank>0;
    $platforms=[];$validated=0;$scores=[];$recent=0;$displaySignals=[];
    foreach($active as $signal){
        $source=(string)($signal['sourceType']??'');
        $isLive=$source==='live_radar'||$source==='live_state'||($signal['eventType']??'')==='live';
        $isActivity=$source==='activity';
        $isManual=in_array($source,['manual_state','manual_admin'],true);
        $isValidated=($signal['status']??'')==='validated';
        foreach((array)($signal['platforms']??[]) as $platform){
            $name=trim((string)$platform);
            if($name!=='')$platforms[$name]=true;
        }
        $timestamp=strtotime((string)($signal['occurredAt']??''));
        if($timestamp!==false&&$timestamp>=time()-86400)$recent++;
        $countsForFusion=$isLive||($isManual&&$isValidated)||($isActivity&&$isValidated&&($ranked||$hasLive));
        if(!$countsForFusion)continue;
        if($isValidated)$validated++;
        $scores[]=(int)($signal['signalScore']??0);
        $displaySignals[]=$signal;
    }
    $official=[];
    foreach((array)($context['officialPlatforms']??[]) as $platform){
        $name=trim((string)$platform);
        if($name!=='')$official[$name]=true;
    }
    rsort($scores);$top=array_slice($scores,0,5);
    $max=$top?$top[0]:0;$average=$top?(int)round(array_sum($top)/count($top)):0;
    $fusionPlatforms=count($platforms);
    $signalScore=$top?min(100,(int)round($max*.55+$average*.25+min(15,$fusionPlatforms*5)+min(10,$validated*5))):0;
    $intelligenceFresh=!empty($profile['fresh']);
    $intelligenceReliable=!empty($profile['sufficientData']);
    $combinedBuzz=$intelligenceReliable&&$intelligenceFresh?(int)round((int)$profile['buzzIndex']*.60+$signalScore*.40):$signalScore;
    $combinedGrowth=$intelligenceReliable?max((int)$profile['growthIndex'],(int)round($signalScore*.65)):(int)round($signalScore*.65);
    if($publicScore>0){
        $combinedBuzz=max($combinedBuzz,(int)round($publicScore));
        $combinedGrowth=max($combinedGrowth,(int)round($publicScore*.85));
    }
    if(!$ranked&&!$hasLive&&!$intelligenceReliable){
        $combinedBuzz=0;$combinedGrowth=0;$signalScore=0;$priority=0;
    }else{
        $priority=(int)round($combinedBuzz*.55+$combinedGrowth*.25+$signalScore*.20);
        if($publicRank>0&&$publicRank<=50)$priority=max($priority,(int)round(100-($publicRank-1)*1.5));
    }
    $visiblePlatforms=$platforms+$official;
    $confidence=($intelligenceReliable&&count($visiblePlatforms)>=2)||$validated>=2||($publicRank>0&&$publicRank<=10)?'élevée':(($ranked||$hasLive||$intelligenceReliable||$validated>=1)?'moyenne':'faible');
    $classification=$priority>=70&&($hasLive||($publicRank>0&&$publicRank<=20)||$validated>=1||count($visiblePlatforms)>=2)?'confirmed_buzz':(($priority>=45||$hasLive||$ranked)?'emerging':($intelligenceReliable&&(float)$profile['globalVariation']<=-15&&$signalScore<35?'decline':'building'));
    return $profile+[
        'signalScore'=>$signalScore,'combinedBuzzIndex'=>max(0,min(100,$combinedBuzz)),
        'combinedGrowthIndex'=>max(0,min(100,$combinedGrowth)),'priorityScore'=>max(0,min(100,$priority)),
        'combinedConfidence'=>$confidence,'classification'=>$classification,'signalCount'=>count($displaySignals),
        'recentSignalCount'=>$recent,'validatedSignalCount'=>$validated,'signalPlatformCount'=>count($visiblePlatforms),
        'signalPlatforms'=>array_keys($visiblePlatforms),'recentSignals'=>array_slice($displaySignals,0,5),
        'publicRank'=>$publicRank?:null,'publicScore'=>$publicScore?:null,'publicPeriod'=>$publicPeriod!==''?$publicPeriod:null,
        'hasLive'=>$hasLive,
    ];
}

function p50_is_dashboard(): array {
    p50_is_ensure_schema();$import=p50_is_import_all();$currentIds=$import['currentIds'];
    $intelligence=p50_is_latest_intelligence($currentIds);$signals=p50_is_signal_rows($currentIds,7);
    $byProfile=[];foreach($signals as $signal)$byProfile[$signal['profileId']][]=$signal;
    $state=p50_de_load_public_state();
    $ranking=p50_is_public_ranking_index($state,'24H');
    $official=p50_is_official_platforms_map($state);
    $profiles=[];
    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id'])||!isset($currentIds[(string)$profile['id']]))continue;
        $id=(string)$profile['id'];
        $base=$intelligence[$id]??[
            'profileId'=>$id,'name'=>(string)($profile['name']??$id),'photo'=>(string)($profile['photoUrl']??$profile['photoCandidateUrl']??''),
            'growthIndex'=>0,'buzzIndex'=>0,'confidenceLevel'=>'faible','globalVariation'=>0.0,'mainVariation'=>'Données insuffisantes',
            'comparisonStatus'=>'insufficient_history','recentData'=>false,'mainSignal'=>'insufficient_data','explanation'=>'Aucune analyse récente.',
            'periodStart'=>null,'periodEnd'=>null,'fresh'=>false,'sufficientData'=>false,
        ];
        $rank=$ranking[$id]??null;
        $profiles[]=p50_is_profile_aggregate($base,$byProfile[$id]??[],[
            'officialPlatforms'=>$official[$id]??p50_is_profile_official_platforms($profile),
            'publicRank'=>$rank['rank']??0,'publicScore'=>$rank['score']??0,'publicPeriod'=>$rank['period']??'24H',
        ]);
    }
    usort($profiles,static fn($a,$b)=>[$b['priorityScore'],$b['signalScore'],$b['combinedBuzzIndex']]<=>[$a['priorityScore'],$a['signalScore'],$a['combinedBuzzIndex']]);
    $alerts=array_values(array_filter($profiles,static fn($p)=>in_array($p['classification'],['confirmed_buzz','emerging'],true)));
    $buzz=array_values(array_filter($profiles,static fn($p)=>$p['classification']==='confirmed_buzz'));
    $trends=array_values(array_filter($profiles,static fn($p)=>$p['combinedGrowthIndex']>=55&&$p['classification']!=='decline'));
    $declines=array_values(array_filter($profiles,static fn($p)=>$p['classification']==='decline'));
    $building=array_values(array_filter($profiles,static fn($p)=>$p['classification']==='building'));
    usort($building,static fn($a,$b)=>strcoll((string)$a['name'],(string)$b['name']));
    $pending=array_values(array_filter($signals,static fn($s)=>$s['status']==='pending'));
    usort($pending,static fn($a,$b)=>[$b['signalScore'],strtotime($b['occurredAt'])]<=>[$a['signalScore'],strtotime($a['occurredAt'])]);
    return [
        'ok'=>true,'version'=>P50_INTELLIGENCE_SIGNALS_V1,'generatedAt'=>gmdate(DATE_ATOM),
        'periodLabel'=>'Signaux 7 jours · lives radar · classement public 24H',
        'summary'=>[
            'profilesAnalyzed'=>count($profiles),'priorityAlerts'=>count($alerts),'confirmedBuzz'=>count($buzz),
            'signalsPending'=>count($pending),'signalsTotal'=>count($signals),'profilesWithSignals'=>count($byProfile),
            'manualSignalsImported'=>$import['manualImported'],'activitySignalsImported'=>$import['activityImported'],
            'liveSignalsImported'=>$import['liveImported'],'registryProfilesDeactivated'=>$import['deactivated'],
        ],
        'priorityAlerts'=>array_slice($alerts,0,10),'signalsPending'=>array_slice($pending,0,30),
        'buzzDetected'=>array_slice($buzz,0,10),'strongTrends'=>array_slice($trends,0,10),
        'declines'=>array_slice($declines,0,10),'buildingSignals'=>array_slice($building,0,20),
    ];
}

function p50_is_review_signal(int $signalId,string $status,?string $userId): bool {
    p50_is_ensure_schema();
    if(!in_array($status,['validated','rejected'],true))return false;
    $stmt=db()->prepare('UPDATE p50_signal_events SET status=?,reviewed_at=UTC_TIMESTAMP(),reviewed_by=?,last_seen_at=UTC_TIMESTAMP() WHERE id=?');
    $stmt->execute([$status,$userId,$signalId]);
    return $stmt->rowCount()>0;
}

function p50_is_create_manual_signal(array $input,?string $userId): int {
    p50_is_ensure_schema();$profileId=trim((string)($input['profileId']??''));$title=trim((string)($input['title']??''));
    if($profileId===''||$title==='')throw new InvalidArgumentException('Profil et titre requis.');
    $profiles=p50_de_registry_profiles($profileId,1,0,false);if(!$profiles)throw new InvalidArgumentException('Profil introuvable.');
    $platforms=array_values(array_filter(array_map('trim',(array)($input['platforms']??[]))));
    p50_is_upsert([
        'key'=>'manual-api|'.$profileId.'|'.microtime(true).'|'.bin2hex(random_bytes(6)),
        'profileId'=>$profileId,'sourceType'=>'manual_admin','sourceId'=>(string)$userId,'eventType'=>'manual',
        'title'=>$title,'platforms'=>$platforms,'evidenceUrl'=>(string)($input['evidenceUrl']??''),
        'evidence'=>['note'=>(string)($input['note']??'')],'signalScore'=>65,'confidenceLevel'=>'moyenne',
        'status'=>'pending','occurredAt'=>p50_de_now(),
    ]);
    return (int)db()->lastInsertId();
}
