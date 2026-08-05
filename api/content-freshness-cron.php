<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-queue-core.php';
require __DIR__.'/content-intelligence-core.php';

const P50_CONTENT_FRESHNESS_VERSION='CONTENT-FRESHNESS-V2.0';

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
if(($input['action']??null)!=='refresh')json_response(['error'=>'Action invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

function p50_cf_ranked_profiles(PDO $pdo,int $limit=8): array {
    $limit=max(1,min(20,$limit));
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
        return array_map('strval',$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }
    if(p50_metrics_column_exists($pdo,'p50_profile_registry','rank_position')){
        $sql="SELECT r.profile_id
          FROM p50_profile_registry r
          LEFT JOIN p50_metric_contents c ON BINARY c.profile_id=BINARY r.profile_id AND c.status='active'
          WHERE r.alive=1 AND r.rank_position BETWEEN 1 AND 70
          GROUP BY r.profile_id,r.rank_position
          ORDER BY MAX(c.last_seen_at) IS NULL DESC,MAX(c.last_seen_at) ASC,r.rank_position ASC
          LIMIT ".$limit;
        return array_map('strval',$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }
    $stmt=$pdo->prepare("SELECT profile_id FROM p50_profile_registry WHERE alive=1 ORDER BY profile_id LIMIT ?");
    $stmt->bindValue(1,$limit,PDO::PARAM_INT);$stmt->execute();
    return array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
}

function p50_cf_candidate_rows(PDO $pdo,array $profileIds): array {
    if(!$profileIds)return [];
    $placeholders=implode(',',array_fill(0,count($profileIds),'?'));
    $threshold=p50_mc_threshold();
    $stmt=$pdo->prepare("SELECT r.profile_id,s.platform
      FROM p50_profile_registry r
      JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id
      WHERE r.alive=1 AND r.profile_id IN ($placeholders)
        AND s.status='verified' AND s.confidence>=?
        AND s.platform IN ('YouTube','X','TikTok','Instagram','Facebook','Snapchat')");
    $stmt->execute([...$profileIds,$threshold]);
    $rows=p50_mo_unique_candidate_rows(array_merge(
        $stmt->fetchAll(),
        p50_mo_oauth_youtube_rows($pdo,$profileIds),
        p50_mo_oauth_meta_rows($pdo,$profileIds)
    ));
    return array_values(array_filter($rows,static fn($row)=>p50_mc_platform_enabled((string)($row['platform']??''))));
}

set_time_limit(240);
$started=microtime(true);
try{
    $pdo=db();
    p50_metrics_ensure_schema($pdo);
    $profiles=p50_cf_ranked_profiles($pdo,8);
    $rows=p50_cf_candidate_rows($pdo,$profiles);
    $enqueued=0;$duplicates=0;$skipped=0;
    foreach($rows as $row){
        $profileId=(string)$row['profile_id'];$platform=(string)$row['platform'];
        $access=p50_mc_public_access($platform,$profileId);
        if(empty($access['configured'])||empty($access['authorized'])){$skipped++;continue;}
        $job=p50_mo_enqueue_profile($pdo,$profileId,$platform,'p0',[
            'priorityOverride'=>5,
            'reason'=>'content_freshness',
            'contentLimit'=>4,
            'dispatchId'=>$dispatchId,
        ]);
        if(!empty($job['created']))$enqueued++;else $duplicates++;
    }

    $processed=0;$completed=0;$partial=0;$failed=0;$retried=0;
    for($iteration=1;$iteration<=24;$iteration++){
        $remaining=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=5 AND status IN ('pending','running','retry_wait')");
        if($remaining===0)break;
        $work=p50_metrics_process_next_job($pdo);
        if(empty($work['processed']))break;
        $processed+=(int)($work['processed']??0);
        $completed+=(int)($work['completed']??0);
        $partial+=(int)($work['partial']??0);
        $failed+=(int)($work['failed']??0);
        $retried+=(int)($work['retried']??0);
    }

    $refresh=p50_ci_refresh($pdo);
    $remaining=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=5 AND status IN ('pending','running','retry_wait')");
    json_response([
        'ok'=>true,
        'version'=>P50_CONTENT_FRESHNESS_VERSION,
        'dispatchId'=>$dispatchId,
        'profilesSelected'=>count($profiles),
        'candidateLinks'=>count($rows),
        'enqueued'=>$enqueued,
        'duplicates'=>$duplicates,
        'skipped'=>$skipped,
        'processed'=>$processed,
        'completed'=>$completed,
        'partial'=>$partial,
        'retried'=>$retried,
        'failed'=>$failed,
        'remaining'=>$remaining,
        'contentIntelligence'=>$refresh,
        'durationMs'=>(int)round((microtime(true)-$started)*1000),
        'publicStateWrites'=>0,
    ]);
}catch(Throwable $error){
    error_log('PASS50 content freshness: '.p50_metrics_safe_error($error->getMessage()));
    json_response(['error'=>'Rafraîchissement rapide des contenus interrompu.','dispatchId'=>$dispatchId,'publicStateWrites'=>0],500);
}
