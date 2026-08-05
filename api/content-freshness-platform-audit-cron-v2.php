<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/content-intelligence-core.php';

const P50_CONTENT_PLATFORM_AUDIT_V2='CONTENT-PLATFORM-AUDIT-V2.1';
const P50_CONTENT_FRESHNESS_RUNTIME='CONTENT-FRESHNESS-V3.2';
const P50_CONTENT_FRESHNESS_BUCKET_MINUTES=5;

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
    'ok'=>true,'action'=>'probe','dispatchId'=>$dispatchId,'contract'=>P50_CONTENT_PLATFORM_AUDIT_V2,
    'contentFreshnessRuntime'=>P50_CONTENT_FRESHNESS_RUNTIME,'collectionBucketMinutes'=>P50_CONTENT_FRESHNESS_BUCKET_MINUTES,
    'readOnly'=>true,'profilesExposed'=>false,'secretsExposed'=>false,'publicStateWrites'=>0,
]);

function p50_cpa2_group(array $rows): array {
    $out=[];
    foreach($rows as $row){
        $platform=trim((string)($row['platform']??''))?:'Unknown';$copy=[];
        foreach($row as $key=>$value)if($key!=='platform')$copy[$key]=is_numeric($value)?(int)$value:$value;
        $out[$platform]=$copy;
    }
    ksort($out,SORT_NATURAL|SORT_FLAG_CASE);return $out;
}
function p50_cpa2_scalar(PDO $pdo,string $sql,array $params=[]): int {$stmt=$pdo->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn();}
function p50_cpa2_iso(?string $value): ?string {$value=trim((string)$value);if($value==='')return null;$ts=strtotime($value.' UTC');return $ts===false?null:gmdate(DATE_ATOM,$ts);}
function p50_cpa2_age(?string $value): ?int {$value=trim((string)$value);if($value==='')return null;$ts=strtotime($value.' UTC');return $ts===false?null:max(0,(int)floor((time()-$ts)/60));}
function p50_cpa2_http_category(int $status): string {
    return match(true){
        $status>=200&&$status<300=>'ok',$status===401=>'unauthorized',$status===402=>'payment_required',
        $status===403=>'forbidden',$status===404=>'not_found',$status===429=>'rate_limited',
        $status>=500=>'server_error',$status>0=>'http_error',default=>'unavailable',
    };
}
function p50_cpa2_x_health(PDO $pdo): array {
    $token=p50_mc_config('X');
    $base=['configured'=>$token!=='','requestAttempted'=>false,'requestSucceeded'=>false,'httpStatus'=>null,'category'=>$token!==''?'not_tested':'configuration_missing'];
    if($token==='')return $base;
    $threshold=p50_mc_threshold();
    $stmt=$pdo->prepare("SELECT s.normalized_url FROM p50_social_links s JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY s.profile_id
      WHERE r.alive=1 AND s.platform='X' AND s.status='verified' AND s.confidence>=?
      ORDER BY s.confidence DESC,s.profile_id ASC LIMIT 1");
    $stmt->execute([$threshold]);$url=(string)($stmt->fetchColumn()?:'');$handle=p50_mc_x_handle($url);
    if($handle==='')return array_replace($base,['category'=>'verified_source_missing']);
    $response=p50_mc_http('https://api.x.com/2/users/by/username/'.rawurlencode($handle).'?user.fields=id',['Authorization: Bearer '.$token]);
    $status=(int)($response['status']??0);
    return ['configured'=>true,'requestAttempted'=>true,'requestSucceeded'=>$status>=200&&$status<300,'httpStatus'=>$status?:null,'category'=>p50_cpa2_http_category($status)];
}
function p50_cpa2_tiktok_health(PDO $pdo): array {
    $verified=p50_cpa2_scalar($pdo,"SELECT COUNT(DISTINCT s.profile_id) FROM p50_social_links s JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY s.profile_id
      WHERE r.alive=1 AND s.platform='TikTok' AND s.status='verified' AND s.confidence>=?",[p50_mc_threshold()]);
    $authorized=function_exists('p50tm_authorized_profile_ids')?count(p50tm_authorized_profile_ids($pdo)):0;
    return ['verifiedProfiles'=>$verified,'authorizedOauthProfiles'=>$authorized,'rapidCycleEligible'=>$authorized>0];
}
function p50_cpa2_top(PDO $pdo,string $period): array {
    $maxAgeHours=['2h'=>24,'24h'=>72,'48h'=>120,'7d'=>240,'15d'=>384][$period]??72;
    $freshSince=gmdate('Y-m-d H:i:s',time()-$maxAgeHours*3600);
    $stmt=$pdo->prepare("SELECT t.rank_position,t.score,t.confidence,t.calculated_at,c.profile_id,c.platform,c.canonical_url,c.title,
      c.published_at,c.first_seen_at,c.platform_content_id,c.metadata_json,n.thumbnail_url
      FROM p50_content_trend_current t JOIN p50_metric_contents c ON c.id=t.content_id
      JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id
      LEFT JOIN p50_news_items n ON n.content_id=c.id AND n.validation_status='published'
      WHERE t.period_key=? AND c.status='active' AND r.alive=1 AND COALESCE(c.published_at,c.first_seen_at)>=?
        AND t.calculated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
      ORDER BY t.rank_position LIMIT 80");
    $stmt->execute([$period,$freshSince]);$selected=[];$perProfile=[];$eligible=[];$latest=null;
    foreach($stmt->fetchAll() as $row){
        $profile=(string)$row['profile_id'];if(($perProfile[$profile]??0)>=2)continue;
        $platform=trim((string)$row['platform'])?:'Unknown';$metadata=p50_ci_decode((string)$row['metadata_json']);
        $thumb=trim((string)($row['thumbnail_url']??''));if($thumb==='')$thumb=(string)(p50_ci_thumbnail($platform,(string)$row['canonical_url'],$row['platform_content_id']!==null?(string)$row['platform_content_id']:null,$metadata)??'');
        $title=trim((string)$row['title']);$titleLength=function_exists('mb_strlen')?mb_strlen($title,'UTF-8'):strlen($title);
        if(strcasecmp($platform,'Facebook')===0&&$titleLength<12&&$thumb==='')continue;
        $eligible[$platform]=($eligible[$platform]??0)+1;if($latest===null)$latest=(string)$row['calculated_at'];
        if(count($selected)<5)$selected[]=['platform'=>$platform,'sourceRank'=>(int)$row['rank_position'],'score'=>(float)$row['score'],'confidence'=>(float)$row['confidence']];
        $perProfile[$profile]=($perProfile[$profile]??0)+1;
    }
    $topCounts=[];foreach($selected as $row)$topCounts[$row['platform']]=($topCounts[$row['platform']]??0)+1;
    ksort($topCounts,SORT_NATURAL|SORT_FLAG_CASE);ksort($eligible,SORT_NATURAL|SORT_FLAG_CASE);
    $youtube=$topCounts['YouTube']??0;
    return ['top5PlatformCounts'=>$topCounts,'eligibleCandidatePlatformCounts'=>$eligible,'youtubeSharePercent'=>$selected?round(100*$youtube/count($selected),2):0,
        'allYoutube'=>count($selected)===5&&$youtube===5,'nonYoutubeCandidatesAvailable'=>count(array_filter($eligible,static fn($count,$platform)=>strcasecmp((string)$platform,'YouTube')!==0&&$count>0,ARRAY_FILTER_USE_BOTH))>0,
        'latestCalculatedAt'=>p50_cpa2_iso($latest)];
}

$started=microtime(true);
try{
    $pdo=db();
    $required=['p50_social_links','p50_metric_accounts','p50_metric_jobs','p50_metric_runs','p50_metric_contents','p50_metric_captures','p50_news_items','p50_content_trend_runs','p50_content_trend_current','p50_profile_registry'];
    $missing=array_values(array_filter($required,static fn(string $table): bool=>!p50_metrics_table_exists($pdo,$table)));
    if($missing)json_response(['ok'=>true,'action'=>'audit','contract'=>P50_CONTENT_PLATFORM_AUDIT_V2,'ready'=>false,'missingTables'=>$missing,'readOnly'=>true,'profilesExposed'=>false,'secretsExposed'=>false,'publicStateWrites'=>0]);
    $threshold=p50_mc_threshold();
    $verifiedStmt=$pdo->prepare("SELECT platform,COUNT(*) links,COUNT(DISTINCT profile_id) profiles FROM p50_social_links WHERE status='verified' AND confidence>=? GROUP BY platform ORDER BY platform");$verifiedStmt->execute([$threshold]);
    $verified=p50_cpa2_group($verifiedStmt->fetchAll());
    $accounts=p50_cpa2_group($pdo->query("SELECT platform,COUNT(*) accounts,COUNT(DISTINCT profile_id) profiles FROM p50_metric_accounts WHERE status='active' GROUP BY platform ORDER BY platform")->fetchAll());
    $jobs30=p50_cpa2_group($pdo->query("SELECT platform,COUNT(*) jobs,COUNT(DISTINCT scope_id) profiles,SUM(status='completed') completed,SUM(status='partial') partial,SUM(status='failed') failed,SUM(status='skipped') skipped,SUM(attempts) attempts FROM p50_metric_jobs WHERE priority=5 AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) GROUP BY platform ORDER BY platform")->fetchAll());
    $runs30=p50_cpa2_group($pdo->query("SELECT COALESCE(r.platform,j.platform) platform,COUNT(*) runs,SUM(r.status='success') success,SUM(r.status='partial') partial,SUM(r.status='failed') failed,SUM(r.contents_found) contentsFound,SUM(r.captures_recorded) capturesRecorded,SUM(r.error_count) errors FROM p50_metric_runs r JOIN p50_metric_jobs j ON j.job_uuid=r.job_uuid WHERE j.priority=5 AND r.started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) GROUP BY COALESCE(r.platform,j.platform) ORDER BY platform")->fetchAll());
    $contents=p50_cpa2_group($pdo->query("SELECT platform,COUNT(*) activeContents,COUNT(DISTINCT profile_id) profiles,SUM(first_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)) new1h,SUM(last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)) refreshed30m FROM p50_metric_contents WHERE status='active' GROUP BY platform ORDER BY platform")->fetchAll());
    $captures24=p50_cpa2_group($pdo->query("SELECT platform,COUNT(*) captures,COUNT(DISTINCT profile_id) profiles,COUNT(DISTINCT content_id) contents,SUM(quality_status='usable') usable,SUM(quality_status='quarantined') quarantined FROM p50_metric_captures WHERE captured_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) GROUP BY platform ORDER BY platform")->fetchAll());
    $news=p50_cpa2_group($pdo->query("SELECT platform,SUM(validation_status='published') publishedTotal,SUM(validation_status='published' AND is_official=1 AND COALESCE(source_published_at,pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR)) official72h,COUNT(DISTINCT CASE WHEN validation_status='published' AND is_official=1 AND COALESCE(source_published_at,pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR) THEN profile_id END) profilesOfficial72h FROM p50_news_items WHERE expires_at IS NULL OR expires_at>UTC_TIMESTAMP() GROUP BY platform ORDER BY platform")->fetchAll());
    $alive=p50_cpa2_scalar($pdo,"SELECT COUNT(*) FROM p50_profile_registry WHERE alive=1");
    $fi=p50_cpa2_scalar($pdo,"SELECT COUNT(DISTINCT profile_id) FROM p50_news_items WHERE validation_status='published' AND is_official=1 AND COALESCE(source_published_at,pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR) AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())");
    $lastJob=$pdo->query("SELECT MAX(created_at) FROM p50_metric_jobs WHERE priority=5")->fetchColumn()?:null;
    $lastRun=$pdo->query("SELECT MAX(r.finished_at) FROM p50_metric_runs r JOIN p50_metric_jobs j ON j.job_uuid=r.job_uuid WHERE j.priority=5 AND r.finished_at IS NOT NULL")->fetchColumn()?:null;
    $trendRun=$pdo->query("SELECT contents_considered,rows_written,finished_at FROM p50_content_trend_runs WHERE status='success' AND finished_at IS NOT NULL ORDER BY finished_at DESC,id DESC LIMIT 1")->fetch()?:null;
    $trends=[];$youtubeOnly=[];foreach(array_keys(p50_ci_periods()) as $period){$trends[$period]=p50_cpa2_top($pdo,$period);if(!empty($trends[$period]['allYoutube']))$youtubeOnly[]=$period;}
    $sourceHealth=['x'=>p50_cpa2_x_health($pdo),'tiktok'=>p50_cpa2_tiktok_health($pdo),'facebook'=>['optionalInsightsDoNotInvalidateCanonicalCaptures'=>true]];
    json_response([
        'ok'=>true,'action'=>'audit','dispatchId'=>$dispatchId,'contract'=>P50_CONTENT_PLATFORM_AUDIT_V2,'readOnly'=>true,'ready'=>true,'generatedAt'=>gmdate(DATE_ATOM),
        'runtime'=>['contentFreshnessVersion'=>P50_CONTENT_FRESHNESS_RUNTIME,'scheduleMinutes'=>5,'collectionBucketMinutes'=>P50_CONTENT_FRESHNESS_BUCKET_MINUTES,'scheduledCyclesPerBucket'=>1],
        'sources'=>['verifiedLinks'=>$verified,'activeMetricAccounts'=>$accounts,'health'=>$sourceHealth],
        'fiveMinuteCollection'=>['jobs30m'=>$jobs30,'runs30m'=>$runs30,'latestJobAt'=>p50_cpa2_iso((string)$lastJob),'latestJobAgeMinutes'=>p50_cpa2_age((string)$lastJob),'latestRunAt'=>p50_cpa2_iso((string)$lastRun),'latestRunAgeMinutes'=>p50_cpa2_age((string)$lastRun)],
        'contentInventory'=>['activeByPlatform'=>$contents,'captures24h'=>$captures24],
        'fiNews'=>['publishedByPlatform'=>$news,'profilesWithOfficialNews72h'=>$fi,'aliveProfiles'=>$alive,'coveragePercent'=>$alive>0?round(100*$fi/$alive,2):0],
        'trends'=>['latestRun'=>$trendRun?['contentsConsidered'=>(int)$trendRun['contents_considered'],'rowsWritten'=>(int)$trendRun['rows_written'],'finishedAt'=>p50_cpa2_iso((string)$trendRun['finished_at'])]:null,'periods'=>$trends,'youtubeOnlyPeriods'=>$youtubeOnly],
        'profilesExposed'=>false,'secretsExposed'=>false,'publicStateWrites'=>0,'durationMs'=>(int)round((microtime(true)-$started)*1000),
    ]);
}catch(Throwable $error){
    error_log('PASS50 content platform audit V2.1: '.p50_metrics_safe_error($error->getMessage()));
    json_response(['error'=>'Audit multiréseau interrompu.','dispatchId'=>$dispatchId,'contract'=>P50_CONTENT_PLATFORM_AUDIT_V2,'profilesExposed'=>false,'secretsExposed'=>false,'publicStateWrites'=>0],500);
}
