<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/content-intelligence-core.php';

const P50_CONTENT_PLATFORM_AUDIT_VERSION='CONTENT-PLATFORM-AUDIT-V1.0';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$contentType=strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
if(!preg_match('~^application/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$~',$contentType))json_response(['error'=>'Type de contenu refusé.'],415);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>16384)json_response(['error'=>'Corps trop volumineux.'],413);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);

$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled'])json_response(['error'=>'Orchestrateur métrique désactivé.'],503);
if(strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);

$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$keys=array_keys($input);sort($keys);if($keys!==['action','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
$action=$input['action']??null;if(!is_string($action)||!in_array($action,['probe','audit'],true))json_response(['error'=>'Action invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

if($action==='probe')json_response([
    'ok'=>true,'action'=>'probe','dispatchId'=>$dispatchId,
    'contract'=>P50_CONTENT_PLATFORM_AUDIT_VERSION,
    'readOnly'=>true,'profilesExposed'=>false,'publicStateWrites'=>0,
]);

function p50_cpa_platform(string|null $value): string {
    $value=trim((string)$value);
    return $value!==''?$value:'Unknown';
}

function p50_cpa_group_rows(array $rows): array {
    $out=[];
    foreach($rows as $row){
        $platform=p50_cpa_platform($row['platform']??null);
        $copy=[];
        foreach($row as $key=>$value){
            if($key==='platform')continue;
            $copy[$key]=is_numeric($value)?(int)$value:$value;
        }
        $out[$platform]=$copy;
    }
    ksort($out,SORT_NATURAL|SORT_FLAG_CASE);
    return $out;
}

function p50_cpa_scalar(PDO $pdo,string $sql,array $params=[]): int {
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function p50_cpa_iso(?string $value): ?string {
    $value=trim((string)$value);if($value==='')return null;
    $ts=strtotime($value.' UTC');return $ts===false?null:gmdate(DATE_ATOM,$ts);
}

function p50_cpa_age_minutes(?string $value): ?int {
    $value=trim((string)$value);if($value==='')return null;
    $ts=strtotime($value.' UTC');return $ts===false?null:max(0,(int)floor((time()-$ts)/60));
}

function p50_cpa_displayed_top(PDO $pdo,string $period): array {
    $maxAgeHours=['2h'=>24,'24h'=>72,'48h'=>120,'7d'=>240,'15d'=>384][$period]??72;
    $freshSince=gmdate('Y-m-d H:i:s',time()-$maxAgeHours*3600);
    $stmt=$pdo->prepare("SELECT t.rank_position,t.score,t.confidence,t.calculated_at,
      c.profile_id,c.platform,c.canonical_url,c.title,c.published_at,c.first_seen_at,c.platform_content_id,c.metadata_json,
      n.thumbnail_url
      FROM p50_content_trend_current t
      JOIN p50_metric_contents c ON c.id=t.content_id
      JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id
      LEFT JOIN p50_news_items n ON n.content_id=c.id AND n.validation_status='published'
      WHERE t.period_key=? AND c.status='active' AND r.alive=1
        AND COALESCE(c.published_at,c.first_seen_at)>=?
        AND t.calculated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
      ORDER BY t.rank_position LIMIT 80");
    $stmt->execute([$period,$freshSince]);
    $selected=[];$perProfile=[];$eligibleByPlatform=[];$nonYoutubeAvailable=false;
    foreach($stmt->fetchAll() as $row){
        $profileId=(string)$row['profile_id'];
        if(($perProfile[$profileId]??0)>=2)continue;
        $platform=p50_cpa_platform($row['platform']??null);
        $metadata=p50_ci_decode((string)$row['metadata_json']);
        $thumbnail=trim((string)($row['thumbnail_url']??''));
        if($thumbnail==='')$thumbnail=(string)(p50_ci_thumbnail($platform,(string)$row['canonical_url'],$row['platform_content_id']!==null?(string)$row['platform_content_id']:null,$metadata)??'');
        $title=trim((string)$row['title']);
        $titleLength=function_exists('mb_strlen')?mb_strlen($title,'UTF-8'):strlen($title);
        if(strcasecmp($platform,'Facebook')===0&&$titleLength<12&&$thumbnail==='')continue;
        $eligibleByPlatform[$platform]=($eligibleByPlatform[$platform]??0)+1;
        if(strcasecmp($platform,'YouTube')!==0)$nonYoutubeAvailable=true;
        if(count($selected)<5){
            $published=$row['published_at']?:$row['first_seen_at'];
            $publishedTs=$published?strtotime((string)$published.' UTC'):false;
            $selected[]=[
                'rank'=>count($selected)+1,
                'sourceRank'=>(int)$row['rank_position'],
                'platform'=>$platform,
                'score'=>(float)$row['score'],
                'confidence'=>(float)$row['confidence'],
                'ageHours'=>$publishedTs===false?null:round(max(0,time()-$publishedTs)/3600,2),
                'hasReadableTitle'=>$titleLength>=12,
                'hasThumbnail'=>$thumbnail!=='',
            ];
        }
        $perProfile[$profileId]=($perProfile[$profileId]??0)+1;
    }
    $topCounts=[];foreach($selected as $row)$topCounts[$row['platform']]=($topCounts[$row['platform']]??0)+1;
    ksort($topCounts,SORT_NATURAL|SORT_FLAG_CASE);ksort($eligibleByPlatform,SORT_NATURAL|SORT_FLAG_CASE);
    $youtubeCount=0;foreach($selected as $row)if(strcasecmp((string)$row['platform'],'YouTube')===0)$youtubeCount++;
    return [
        'period'=>$period,
        'maxAgeHours'=>$maxAgeHours,
        'top5'=>$selected,
        'top5PlatformCounts'=>$topCounts,
        'eligibleCandidatePlatformCounts'=>$eligibleByPlatform,
        'distinctTop5Platforms'=>count($topCounts),
        'youtubeCount'=>$youtubeCount,
        'youtubeSharePercent'=>$selected?round(100*$youtubeCount/count($selected),2):0,
        'allYoutube'=>count($selected)===5&&$youtubeCount===5,
        'nonYoutubeCandidatesAvailable'=>$nonYoutubeAvailable,
        'latestCalculatedAt'=>$selected?p50_cpa_iso((string)($stmtRow['calculated_at']??'')):null,
    ];
}

$started=microtime(true);
try{
    $pdo=db();
    $required=['p50_social_links','p50_metric_accounts','p50_metric_jobs','p50_metric_runs','p50_metric_contents','p50_metric_captures','p50_news_items','p50_content_trend_runs','p50_content_trend_current','p50_profile_registry'];
    $missing=array_values(array_filter($required,static fn(string $table): bool=>!p50_metrics_table_exists($pdo,$table)));
    if($missing)json_response([
        'ok'=>true,'action'=>'audit','dispatchId'=>$dispatchId,'contract'=>P50_CONTENT_PLATFORM_AUDIT_VERSION,
        'readOnly'=>true,'ready'=>false,'missingTables'=>$missing,'profilesExposed'=>false,'publicStateWrites'=>0,
    ]);

    $threshold=p50_mc_threshold();
    $verifiedStmt=$pdo->prepare("SELECT platform,COUNT(*) links,COUNT(DISTINCT profile_id) profiles
      FROM p50_social_links WHERE status='verified' AND confidence>=? GROUP BY platform ORDER BY platform");
    $verifiedStmt->execute([$threshold]);
    $verifiedLinks=p50_cpa_group_rows($verifiedStmt->fetchAll());

    $accounts=p50_cpa_group_rows($pdo->query("SELECT platform,COUNT(*) accounts,COUNT(DISTINCT profile_id) profiles
      FROM p50_metric_accounts WHERE status='active' GROUP BY platform ORDER BY platform")->fetchAll());

    $jobs30m=p50_cpa_group_rows($pdo->query("SELECT platform,COUNT(*) jobs,COUNT(DISTINCT scope_id) profiles,
      SUM(status='pending') pending,SUM(status='running') running,SUM(status='retry_wait') retryWait,
      SUM(status='completed') completed,SUM(status='partial') partial,SUM(status='failed') failed,SUM(status='skipped') skipped,
      SUM(attempts) attempts
      FROM p50_metric_jobs WHERE priority=5 AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
      GROUP BY platform ORDER BY platform")->fetchAll());
    $jobs24h=p50_cpa_group_rows($pdo->query("SELECT platform,COUNT(*) jobs,COUNT(DISTINCT scope_id) profiles,
      SUM(status='completed') completed,SUM(status='partial') partial,SUM(status='failed') failed,SUM(status='skipped') skipped,
      SUM(attempts) attempts
      FROM p50_metric_jobs WHERE priority=5 AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
      GROUP BY platform ORDER BY platform")->fetchAll());

    $runs30m=p50_cpa_group_rows($pdo->query("SELECT COALESCE(r.platform,j.platform) platform,COUNT(*) runs,
      SUM(r.status='success') success,SUM(r.status='partial') partial,SUM(r.status='failed') failed,
      SUM(r.contents_found) contentsFound,SUM(r.captures_recorded) capturesRecorded,
      SUM(r.duplicates_skipped) duplicates,SUM(r.quarantined_count) quarantined,SUM(r.error_count) errors
      FROM p50_metric_runs r JOIN p50_metric_jobs j ON j.job_uuid=r.job_uuid
      WHERE j.priority=5 AND r.started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
      GROUP BY COALESCE(r.platform,j.platform) ORDER BY platform")->fetchAll());
    $runs24h=p50_cpa_group_rows($pdo->query("SELECT COALESCE(r.platform,j.platform) platform,COUNT(*) runs,
      SUM(r.status='success') success,SUM(r.status='partial') partial,SUM(r.status='failed') failed,
      SUM(r.contents_found) contentsFound,SUM(r.captures_recorded) capturesRecorded,
      SUM(r.duplicates_skipped) duplicates,SUM(r.quarantined_count) quarantined,SUM(r.error_count) errors
      FROM p50_metric_runs r JOIN p50_metric_jobs j ON j.job_uuid=r.job_uuid
      WHERE j.priority=5 AND r.started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
      GROUP BY COALESCE(r.platform,j.platform) ORDER BY platform")->fetchAll());

    $contents=p50_cpa_group_rows($pdo->query("SELECT platform,COUNT(*) activeContents,COUNT(DISTINCT profile_id) profiles,
      SUM(first_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)) new1h,
      SUM(first_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)) new24h,
      SUM(last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)) refreshed30m,
      SUM(title<>'') titled
      FROM p50_metric_contents WHERE status='active' GROUP BY platform ORDER BY platform")->fetchAll());

    $captures30m=p50_cpa_group_rows($pdo->query("SELECT platform,COUNT(*) captures,COUNT(DISTINCT profile_id) profiles,
      COUNT(DISTINCT content_id) contents,SUM(quality_status='usable') usable,SUM(quality_status='quarantined') quarantined
      FROM p50_metric_captures WHERE captured_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
      GROUP BY platform ORDER BY platform")->fetchAll());
    $captures24h=p50_cpa_group_rows($pdo->query("SELECT platform,COUNT(*) captures,COUNT(DISTINCT profile_id) profiles,
      COUNT(DISTINCT content_id) contents,SUM(quality_status='usable') usable,SUM(quality_status='quarantined') quarantined
      FROM p50_metric_captures WHERE captured_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
      GROUP BY platform ORDER BY platform")->fetchAll());

    $news=p50_cpa_group_rows($pdo->query("SELECT platform,
      SUM(validation_status='published') publishedTotal,
      SUM(validation_status='published' AND is_official=1 AND COALESCE(source_published_at,pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR)) official72h,
      COUNT(DISTINCT CASE WHEN validation_status='published' AND is_official=1 AND COALESCE(source_published_at,pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR) THEN profile_id END) profilesOfficial72h,
      SUM(first_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)) inserted1h,
      SUM(last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)) touched1h
      FROM p50_news_items WHERE expires_at IS NULL OR expires_at>UTC_TIMESTAMP()
      GROUP BY platform ORDER BY platform")->fetchAll());

    $aliveProfiles=p50_cpa_scalar($pdo,"SELECT COUNT(*) FROM p50_profile_registry WHERE alive=1");
    $fiProfiles=p50_cpa_scalar($pdo,"SELECT COUNT(DISTINCT profile_id) FROM p50_news_items
      WHERE validation_status='published' AND is_official=1
        AND COALESCE(source_published_at,pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR)
        AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())");

    $trendRun=$pdo->query("SELECT run_uuid,status,contents_considered,rows_written,started_at,finished_at
      FROM p50_content_trend_runs ORDER BY id DESC LIMIT 1")->fetch()?:null;
    $trends=[];$youtubeOnlyPeriods=[];
    foreach(array_keys(p50_ci_periods()) as $period){
        $trends[$period]=p50_cpa_displayed_top($pdo,$period);
        if(!empty($trends[$period]['allYoutube']))$youtubeOnlyPeriods[]=$period;
    }

    $lastJob=$pdo->query("SELECT MAX(created_at) FROM p50_metric_jobs WHERE priority=5")->fetchColumn()?:null;
    $lastFreshRun=$pdo->query("SELECT MAX(r.finished_at) FROM p50_metric_runs r JOIN p50_metric_jobs j ON j.job_uuid=r.job_uuid
      WHERE j.priority=5 AND r.finished_at IS NOT NULL")->fetchColumn()?:null;
    $latestTrendFinished=$trendRun['finished_at']??null;
    $job30mTotal=array_sum(array_map(static fn($row)=>(int)($row['jobs']??0),$jobs30m));
    $runs30mTotal=array_sum(array_map(static fn($row)=>(int)($row['runs']??0),$runs30m));
    $newContents1h=array_sum(array_map(static fn($row)=>(int)($row['new1h']??0),$contents));
    $newsInserted1h=array_sum(array_map(static fn($row)=>(int)($row['inserted1h']??0),$news));
    $capturePlatforms24h=count(array_filter($captures24h,static fn($row)=>(int)($row['usable']??0)>0));
    $newsPlatforms72h=count(array_filter($news,static fn($row)=>(int)($row['official72h']??0)>0));

    $diagnosis=[
        'fiveMinuteScheduleConfigured'=>true,
        'latestPriority5JobAt'=>p50_cpa_iso((string)$lastJob),
        'latestPriority5JobAgeMinutes'=>p50_cpa_age_minutes((string)$lastJob),
        'latestPriority5RunAt'=>p50_cpa_iso((string)$lastFreshRun),
        'latestPriority5RunAgeMinutes'=>p50_cpa_age_minutes((string)$lastFreshRun),
        'latestTrendRunAt'=>p50_cpa_iso((string)$latestTrendFinished),
        'latestTrendRunAgeMinutes'=>p50_cpa_age_minutes((string)$latestTrendFinished),
        'jobs30m'=>$job30mTotal,'runs30m'=>$runs30mTotal,
        'newContents1h'=>$newContents1h,'newsInserted1h'=>$newsInserted1h,
        'usableCapturePlatforms24h'=>$capturePlatforms24h,
        'officialNewsPlatforms72h'=>$newsPlatforms72h,
        'fiProfilesWithOfficialNews72h'=>$fiProfiles,
        'aliveProfiles'=>$aliveProfiles,
        'fiCoveragePercent'=>$aliveProfiles>0?round(100*$fiProfiles/$aliveProfiles,2):0,
        'youtubeOnlyTop5Periods'=>$youtubeOnlyPeriods,
    ];

    json_response([
        'ok'=>true,'action'=>'audit','dispatchId'=>$dispatchId,'contract'=>P50_CONTENT_PLATFORM_AUDIT_VERSION,
        'readOnly'=>true,'ready'=>true,'generatedAt'=>gmdate(DATE_ATOM),
        'sources'=>['verifiedLinks'=>$verifiedLinks,'activeMetricAccounts'=>$accounts],
        'fiveMinuteCollection'=>['jobs30m'=>$jobs30m,'jobs24h'=>$jobs24h,'runs30m'=>$runs30m,'runs24h'=>$runs24h],
        'contentInventory'=>['activeByPlatform'=>$contents,'captures30m'=>$captures30m,'captures24h'=>$captures24h],
        'fiNews'=>['publishedByPlatform'=>$news,'profilesWithOfficialNews72h'=>$fiProfiles,'aliveProfiles'=>$aliveProfiles],
        'trends'=>['latestRun'=>$trendRun?[
            'runUuid'=>(string)$trendRun['run_uuid'],'status'=>(string)$trendRun['status'],
            'contentsConsidered'=>(int)$trendRun['contents_considered'],'rowsWritten'=>(int)$trendRun['rows_written'],
            'startedAt'=>p50_cpa_iso((string)$trendRun['started_at']),'finishedAt'=>p50_cpa_iso((string)$trendRun['finished_at']),
        ]:null,'periods'=>$trends],
        'diagnosis'=>$diagnosis,
        'profilesExposed'=>false,'publicStateWrites'=>0,
        'durationMs'=>(int)round((microtime(true)-$started)*1000),
    ]);
}catch(Throwable $error){
    error_log('PASS50 content platform audit: '.p50_metrics_safe_error($error->getMessage()));
    json_response(['error'=>'Audit des sources d’actualité interrompu.','dispatchId'=>$dispatchId,'publicStateWrites'=>0],500);
}
