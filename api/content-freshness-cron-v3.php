<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-queue-core.php';
require __DIR__.'/content-intelligence-core.php';

const P50_CONTENT_FRESHNESS_V3_VERSION='CONTENT-FRESHNESS-V3.0';
const P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS=300;
const P50_CONTENT_FRESHNESS_V3_PROFILE_LIMIT=8;
const P50_CONTENT_FRESHNESS_V3_JOB_LIMIT=16;

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
$action=$input['action']??null;if(!is_string($action)||!in_array($action,['probe','refresh'],true))json_response(['error'=>'Action invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

if($action==='probe')json_response([
    'ok'=>true,'action'=>'probe','dispatchId'=>$dispatchId,
    'version'=>P50_CONTENT_FRESHNESS_V3_VERSION,
    'bucketSeconds'=>P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS,
    'publicStateWrites'=>0,
]);

function p50_cf3_ranked_profiles(PDO $pdo,int $limit=70): array {
    $limit=max(8,min(150,$limit));
    if(p50_metrics_table_exists($pdo,'p50_ranking_snapshots')){
        $sql="SELECT ranked.profile_id,ranked.rank_position,MAX(c.last_seen_at) latest_content
          FROM (
            SELECT r.profile_id,MIN(s.rank_position) rank_position
            FROM p50_profile_registry r
            JOIN p50_ranking_snapshots s ON BINARY s.profile_id=BINARY r.profile_id
            JOIN (SELECT profile_id,MAX(captured_at) captured_at FROM p50_ranking_snapshots GROUP BY profile_id) latest
              ON BINARY latest.profile_id=BINARY s.profile_id AND latest.captured_at=s.captured_at
            WHERE r.alive=1 AND s.rank_position BETWEEN 1 AND 70
            GROUP BY r.profile_id
          ) ranked
          LEFT JOIN p50_metric_contents c ON BINARY c.profile_id=BINARY ranked.profile_id AND c.status='active'
          GROUP BY ranked.profile_id,ranked.rank_position
          ORDER BY latest_content IS NULL DESC,latest_content ASC,ranked.rank_position ASC
          LIMIT ".$limit;
        return $pdo->query($sql)->fetchAll();
    }
    if(p50_metrics_column_exists($pdo,'p50_profile_registry','rank_position')){
        $sql="SELECT r.profile_id,r.rank_position,MAX(c.last_seen_at) latest_content
          FROM p50_profile_registry r
          LEFT JOIN p50_metric_contents c ON BINARY c.profile_id=BINARY r.profile_id AND c.status='active'
          WHERE r.alive=1 AND r.rank_position BETWEEN 1 AND 70
          GROUP BY r.profile_id,r.rank_position
          ORDER BY latest_content IS NULL DESC,latest_content ASC,r.rank_position ASC
          LIMIT ".$limit;
        return $pdo->query($sql)->fetchAll();
    }
    $stmt=$pdo->prepare("SELECT profile_id,9999 rank_position,NULL latest_content FROM p50_profile_registry WHERE alive=1 ORDER BY profile_id LIMIT ?");
    $stmt->bindValue(1,$limit,PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
}

function p50_cf3_platform_counter(array &$counter,string $platform,string $key,int $increment=1): void {
    if(!isset($counter[$platform]))$counter[$platform]=[];
    $counter[$platform][$key]=(int)($counter[$platform][$key]??0)+$increment;
}

function p50_cf3_authorized_rows(PDO $pdo,array $ranked): array {
    $ids=array_values(array_filter(array_map(static fn($row)=>trim((string)($row['profile_id']??'')),$ranked)));
    $summary=['verified'=>0,'enabled'=>0,'authorized'=>0,'disabled'=>0,'configurationMissing'=>0,'authorizationRequired'=>0,'unsupported'=>0];
    $byPlatform=[];
    if(!$ids)return ['rows'=>[],'summary'=>$summary,'byPlatform'=>$byPlatform];
    $placeholders=implode(',',array_fill(0,count($ids),'?'));$threshold=p50_mc_threshold();
    $stmt=$pdo->prepare("SELECT r.profile_id,s.platform
      FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id
      WHERE r.alive=1 AND r.profile_id IN ($placeholders)
        AND s.status='verified' AND s.confidence>=?
        AND s.platform IN ('YouTube','X','TikTok','Instagram','Facebook','Snapchat')");
    $stmt->execute([...$ids,$threshold]);
    $rows=p50_mo_unique_candidate_rows(array_merge($stmt->fetchAll(),p50_mo_oauth_youtube_rows($pdo,$ids),p50_mo_oauth_meta_rows($pdo,$ids)));
    $summary['verified']=count($rows);

    $seenStmt=$pdo->prepare("SELECT profile_id,platform,MAX(last_seen_at) last_seen_at
      FROM p50_metric_contents WHERE status='active' AND profile_id IN ($placeholders)
      GROUP BY profile_id,platform");
    $seenStmt->execute($ids);$seen=[];
    foreach($seenStmt->fetchAll() as $row)$seen[(string)$row['profile_id'].'|'.(string)$row['platform']]=$row['last_seen_at']?:null;

    $authorized=[];
    foreach($rows as $row){
        $profileId=(string)$row['profile_id'];$platform=p50_mc_platform((string)$row['platform']);
        p50_cf3_platform_counter($byPlatform,$platform,'verified');
        if(!p50_mc_platform_enabled($platform)){$summary['disabled']++;p50_cf3_platform_counter($byPlatform,$platform,'disabled');continue;}
        $summary['enabled']++;p50_cf3_platform_counter($byPlatform,$platform,'enabled');
        $access=p50_mc_public_access($platform,$profileId);$mode=(string)($access['mode']??'');
        if($mode==='unsupported_account_type'){$summary['unsupported']++;p50_cf3_platform_counter($byPlatform,$platform,'unsupported');continue;}
        if(empty($access['configured'])){$summary['configurationMissing']++;p50_cf3_platform_counter($byPlatform,$platform,'configurationMissing');continue;}
        if(empty($access['authorized'])){$summary['authorizationRequired']++;p50_cf3_platform_counter($byPlatform,$platform,'authorizationRequired');continue;}
        $summary['authorized']++;p50_cf3_platform_counter($byPlatform,$platform,'authorized');
        $authorized[]=['profileId'=>$profileId,'platform'=>$platform,'lastContentAt'=>$seen[$profileId.'|'.$platform]??null];
    }
    ksort($byPlatform,SORT_NATURAL|SORT_FLAG_CASE);
    return ['rows'=>$authorized,'summary'=>$summary,'byPlatform'=>$byPlatform];
}

function p50_cf3_select(array $ranked,array $authorized,int $profileLimit=P50_CONTENT_FRESHNESS_V3_PROFILE_LIMIT,int $jobLimit=P50_CONTENT_FRESHNESS_V3_JOB_LIMIT): array {
    $byProfile=[];foreach($authorized as $row)$byProfile[(string)$row['profileId']][]=$row;
    $selectedProfiles=[];$selectedJobs=[];$secondaries=[];$loads=[];
    foreach($ranked as $rankRow){
        if(count($selectedProfiles)>=$profileLimit)break;
        $profileId=(string)($rankRow['profile_id']??'');$options=$byProfile[$profileId]??[];
        if(!$options)continue;
        usort($options,static function($a,$b) use(&$loads){
            $at=trim((string)($a['lastContentAt']??''));$bt=trim((string)($b['lastContentAt']??''));
            if(($at==='')!==($bt===''))return $at===''?-1:1;
            if($at!==$bt)return strcmp($at,$bt);
            $loadCompare=(int)($loads[$a['platform']]??0)<=>(int)($loads[$b['platform']]??0);
            return $loadCompare!==0?$loadCompare:strcmp((string)$a['platform'],(string)$b['platform']);
        });
        $primary=array_shift($options);$primary['rankPosition']=(int)($rankRow['rank_position']??0);$primary['role']='primary';
        $selectedJobs[]=$primary;$selectedProfiles[$profileId]=true;$loads[$primary['platform']]=(int)($loads[$primary['platform']]??0)+1;
        foreach($options as $option){$option['rankPosition']=(int)($rankRow['rank_position']??0);$option['role']='secondary';$secondaries[]=$option;}
    }
    usort($secondaries,static function($a,$b) use(&$loads){
        $loadCompare=(int)($loads[$a['platform']]??0)<=>(int)($loads[$b['platform']]??0);
        if($loadCompare!==0)return $loadCompare;
        $at=trim((string)($a['lastContentAt']??''));$bt=trim((string)($b['lastContentAt']??''));
        if(($at==='')!==($bt===''))return $at===''?-1:1;
        if($at!==$bt)return strcmp($at,$bt);
        return (int)$a['rankPosition']<=>(int)$b['rankPosition'];
    });
    foreach($secondaries as $row){
        if(count($selectedJobs)>=$jobLimit)break;
        $selectedJobs[]=$row;$loads[$row['platform']]=(int)($loads[$row['platform']]??0)+1;
    }
    $platforms=[];foreach($selectedJobs as $row)p50_cf3_platform_counter($platforms,(string)$row['platform'],'selected');
    ksort($platforms,SORT_NATURAL|SORT_FLAG_CASE);
    return ['profiles'=>array_keys($selectedProfiles),'jobs'=>$selectedJobs,'platforms'=>$platforms];
}

function p50_cf3_enqueue(PDO $pdo,array $row,string $dispatchId,int $now): array {
    $profileId=(string)$row['profileId'];$platform=(string)$row['platform'];
    $start=(int)(floor($now/P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS)*P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS);
    $bucket=gmdate('YmdHis',$start);$observedAt=gmdate('Y-m-d H:i:s',$now);
    $idempotency=hash('sha256',implode('|',[P50_CONTENT_FRESHNESS_V3_VERSION,$bucket,$profileId,$platform]));
    $payload=[
        'profileId'=>$profileId,'platform'=>$platform,'contentLimit'=>4,
        'observedAt'=>$observedAt,'liveConfirmed'=>false,'cadence'=>'p0',
        'bucket'=>$bucket,'dispatchId'=>$dispatchId,'reason'=>'content_freshness_v3',
    ];
    return p50_metrics_enqueue_job($pdo,[
        'idempotencyKey'=>$idempotency,'collector'=>strtolower($platform).'_v1','platform'=>$platform,
        'scopeType'=>'profile','scopeId'=>$profileId,'priority'=>5,'maxAttempts'=>3,'payload'=>$payload,
    ])+['platform'=>$platform,'bucket'=>$bucket];
}

set_time_limit(280);$started=microtime(true);$stage='bootstrap';
try{
    $pdo=db();p50_metrics_ensure_schema($pdo);p50_metrics_recover_stale_jobs($pdo);
    $stage='selection';$ranked=p50_cf3_ranked_profiles($pdo,70);$access=p50_cf3_authorized_rows($pdo,$ranked);
    $selection=p50_cf3_select($ranked,$access['rows']);

    $stage='enqueue';$enqueued=0;$duplicates=0;$enqueueByPlatform=[];$now=time();
    foreach($selection['jobs'] as $row){
        $job=p50_cf3_enqueue($pdo,$row,$dispatchId,$now);$platform=(string)$row['platform'];
        if(!empty($job['created'])){$enqueued++;p50_cf3_platform_counter($enqueueByPlatform,$platform,'enqueued');}
        else{$duplicates++;p50_cf3_platform_counter($enqueueByPlatform,$platform,'duplicates');}
    }

    $stage='work';$processed=0;$completed=0;$partial=0;$failed=0;$retried=0;$skipped=0;$processedByPlatform=[];
    for($iteration=1;$iteration<=24;$iteration++){
        $remaining=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=5 AND status IN ('pending','running','retry_wait')");
        if($remaining===0)break;
        $work=p50_metrics_process_next_job($pdo);
        if(empty($work['processed']))break;
        $processed+=(int)($work['processed']??0);$completed+=(int)($work['completed']??0);$partial+=(int)($work['partial']??0);
        $failed+=(int)($work['failed']??0);$retried+=(int)($work['retried']??0);$skipped+=(int)($work['skipped']??0);
        $jobStmt=$pdo->prepare("SELECT platform,priority FROM p50_metric_jobs WHERE job_uuid=? LIMIT 1");$jobStmt->execute([(string)$work['jobUuid']]);$jobRow=$jobStmt->fetch()?:[];
        if((int)($jobRow['priority']??-1)===5){
            $platform=p50_mc_platform((string)($jobRow['platform']??'Unknown'));p50_cf3_platform_counter($processedByPlatform,$platform,'processed');
            p50_cf3_platform_counter($processedByPlatform,$platform,(string)($work['status']??'unknown'));
        }
    }

    $stage='content_intelligence';$refresh=p50_ci_refresh($pdo);
    $remaining=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=5 AND status IN ('pending','running','retry_wait')");
    ksort($enqueueByPlatform,SORT_NATURAL|SORT_FLAG_CASE);ksort($processedByPlatform,SORT_NATURAL|SORT_FLAG_CASE);
    json_response([
        'ok'=>true,'action'=>'refresh','version'=>P50_CONTENT_FRESHNESS_V3_VERSION,'dispatchId'=>$dispatchId,
        'bucketSeconds'=>P50_CONTENT_FRESHNESS_V3_BUCKET_SECONDS,
        'profilesScanned'=>count($ranked),'profilesSelected'=>count($selection['profiles']),
        'candidateLinks'=>(int)$access['summary']['verified'],'authorizedLinks'=>(int)$access['summary']['authorized'],
        'accessSummary'=>$access['summary'],'accessByPlatform'=>$access['byPlatform'],
        'selectedByPlatform'=>$selection['platforms'],'enqueueByPlatform'=>$enqueueByPlatform,'processedByPlatform'=>$processedByPlatform,
        'enqueued'=>$enqueued,'duplicates'=>$duplicates,'processed'=>$processed,'completed'=>$completed,'partial'=>$partial,
        'retried'=>$retried,'failed'=>$failed,'skipped'=>$skipped,'remaining'=>$remaining,
        'contentIntelligence'=>$refresh,'stage'=>'complete',
        'durationMs'=>(int)round((microtime(true)-$started)*1000),'publicStateWrites'=>0,
    ]);
}catch(Throwable $error){
    $detail=p50_metrics_safe_error($error->getMessage());
    error_log('PASS50 content freshness V3 ['.$stage.']: '.$detail);
    json_response([
        'error'=>'Rafraîchissement rapide des contenus interrompu.','errorCode'=>'content_freshness_'.$stage,
        'detail'=>$detail,'stage'=>$stage,'dispatchId'=>$dispatchId,
        'version'=>P50_CONTENT_FRESHNESS_V3_VERSION,'publicStateWrites'=>0,
        'durationMs'=>(int)round((microtime(true)-$started)*1000),
    ],500);
}
