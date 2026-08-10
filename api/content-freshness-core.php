<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-queue-core.php';
require_once __DIR__.'/content-intelligence-core.php';

const P50_CONTENT_FRESHNESS_V4_VERSION='CONTENT-FRESHNESS-V4.0';
const P50_CONTENT_FRESHNESS_V4_BUCKET_SECONDS=300;
const P50_CONTENT_FRESHNESS_V4_PROFILE_LIMIT=16;
const P50_CONTENT_FRESHNESS_V4_JOB_LIMIT=28;
const P50_CONTENT_FRESHNESS_V4_WORK_ITERATIONS=36;
const P50_CONTENT_FRESHNESS_V4_TOP_RANK_FORCE=3;
const P50_CONTENT_FRESHNESS_V4_TIKTOK_OAUTH_LIMIT=4;
const P50_CONTENT_FRESHNESS_V4_FACEBOOK_COLLECTOR='FACEBOOK-COLLECTOR-V2.0';
const P50_CONTENT_FRESHNESS_V4_X_PAUSE_REASON='payment_required';

function p50_cf4_x_fast_cycle_enabled(): bool {
    global $config;
    $value=$config['metrics']['x_fast_cycle_enabled']??getenv('PASS50_X_FAST_CYCLE_ENABLED');
    if($value===false||$value===null||trim((string)$value)==='')return false;
    return filter_var($value,FILTER_VALIDATE_BOOLEAN);
}

function p50_cf4_x_policy(): array {
    $enabled=p50_cf4_x_fast_cycle_enabled();
    return ['enabled'=>$enabled,'reason'=>$enabled?null:P50_CONTENT_FRESHNESS_V4_X_PAUSE_REASON,'confirmedHttpStatus'=>$enabled?null:402];
}

function p50_cf4_ranked_profiles(PDO $pdo,int $limit=70): array {
    $limit=max(8,min(150,$limit));
    if(p50_metrics_table_exists($pdo,'p50_metric_ranking_current')){
        $algorithm=defined('P50_MR_ALGORITHM_VERSION')?P50_MR_ALGORITHM_VERSION:'MR-V1.0';
        $sql="SELECT ordered.profile_id,ordered.rank_position,ordered.latest_content
          FROM (
            SELECT ranked.profile_id,ranked.rank_position,MAX(c.last_seen_at) AS latest_content
            FROM (
              SELECT c.profile_id,c.rank_position
              FROM p50_metric_ranking_current c
              JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id
              WHERE r.alive=1 AND c.algorithm_version=? AND c.period_key='24H'
                AND c.rank_position IS NOT NULL AND c.rank_position BETWEEN 1 AND 70
            ) ranked
            LEFT JOIN p50_metric_contents c ON BINARY c.profile_id=BINARY ranked.profile_id AND c.status='active'
            GROUP BY ranked.profile_id,ranked.rank_position
          ) ordered
          ORDER BY CASE WHEN ordered.latest_content IS NULL THEN 0 ELSE 1 END ASC,
            ordered.latest_content ASC,ordered.rank_position ASC
          LIMIT ".$limit;
        $stmt=$pdo->prepare($sql);$stmt->execute([$algorithm]);return $stmt->fetchAll();
    }
    if(p50_metrics_table_exists($pdo,'p50_ranking_snapshots')){
        $sql="SELECT ordered.profile_id,ordered.rank_position,ordered.latest_content
          FROM (
            SELECT ranked.profile_id,ranked.rank_position,MAX(c.last_seen_at) AS latest_content
            FROM (
              SELECT r.profile_id,MIN(s.rank_position) AS rank_position
              FROM p50_profile_registry r
              JOIN p50_ranking_snapshots s ON BINARY s.profile_id=BINARY r.profile_id
              JOIN (SELECT profile_id,MAX(captured_at) AS captured_at FROM p50_ranking_snapshots GROUP BY profile_id) latest
                ON BINARY latest.profile_id=BINARY s.profile_id AND latest.captured_at=s.captured_at
              WHERE r.alive=1 AND s.rank_position BETWEEN 1 AND 70
              GROUP BY r.profile_id
            ) ranked
            LEFT JOIN p50_metric_contents c ON BINARY c.profile_id=BINARY ranked.profile_id AND c.status='active'
            GROUP BY ranked.profile_id,ranked.rank_position
          ) ordered
          ORDER BY CASE WHEN ordered.latest_content IS NULL THEN 0 ELSE 1 END ASC,
            ordered.latest_content ASC,ordered.rank_position ASC
          LIMIT ".$limit;
        return $pdo->query($sql)->fetchAll();
    }
    if(p50_metrics_column_exists($pdo,'p50_profile_registry','rank_position')){
        $sql="SELECT ordered.profile_id,ordered.rank_position,ordered.latest_content
          FROM (
            SELECT r.profile_id,r.rank_position,MAX(c.last_seen_at) AS latest_content
            FROM p50_profile_registry r
            LEFT JOIN p50_metric_contents c ON BINARY c.profile_id=BINARY r.profile_id AND c.status='active'
            WHERE r.alive=1 AND r.rank_position BETWEEN 1 AND 70
            GROUP BY r.profile_id,r.rank_position
          ) ordered
          ORDER BY CASE WHEN ordered.latest_content IS NULL THEN 0 ELSE 1 END ASC,
            ordered.latest_content ASC,ordered.rank_position ASC
          LIMIT ".$limit;
        return $pdo->query($sql)->fetchAll();
    }
    $stmt=$pdo->prepare("SELECT profile_id,9999 rank_position,NULL latest_content FROM p50_profile_registry WHERE alive=1 ORDER BY profile_id LIMIT ?");
    $stmt->bindValue(1,$limit,PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll();
}

function p50_cf4_ranking_period_keys(): array {
    if(function_exists('p50_mr_period_hours'))return array_keys(p50_mr_period_hours());
    return ['2H','24H','48H','7J','15J'];
}

function p50_cf4_top_profiles_all_periods(PDO $pdo,int $topN=P50_CONTENT_FRESHNESS_V4_TOP_RANK_FORCE): array {
    if(!p50_metrics_table_exists($pdo,'p50_metric_ranking_current'))return ['rows'=>[],'count'=>0,'periods'=>[]];
    $topN=max(1,min(10,$topN));
    $algorithm=defined('P50_MR_ALGORITHM_VERSION')?P50_MR_ALGORITHM_VERSION:'MR-V1.0';
    $rows=[];$seen=[];$byPeriod=[];
    $stmt=$pdo->prepare("SELECT c.profile_id,c.rank_position,MAX(mc.last_seen_at) AS latest_content
      FROM p50_metric_ranking_current c
      JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id
      LEFT JOIN p50_metric_contents mc ON BINARY mc.profile_id=BINARY c.profile_id AND mc.status='active'
      WHERE r.alive=1 AND c.algorithm_version=? AND c.period_key=?
        AND c.rank_position IS NOT NULL AND c.rank_position BETWEEN 1 AND ?
      GROUP BY c.profile_id,c.rank_position
      ORDER BY c.rank_position ASC");
    foreach(p50_cf4_ranking_period_keys() as $periodKey){
        $stmt->execute([$algorithm,$periodKey,$topN]);
        foreach($stmt->fetchAll() as $row){
            $id=trim((string)($row['profile_id']??''));if($id===''||isset($seen[$id]))continue;
            $seen[$id]=true;
            $rows[]=[
                'profile_id'=>$id,
                'rank_position'=>(int)$row['rank_position'],
                'latest_content'=>$row['latest_content']??null,
                'priority_period'=>$periodKey,
            ];
            $byPeriod[$periodKey]=($byPeriod[$periodKey]??0)+1;
        }
    }
    return ['rows'=>$rows,'count'=>count($rows),'periods'=>$byPeriod];
}

function p50_cf4_merge_ranked_lists(array $priority,array $ranked): array {
    $seen=[];$merged=[];
    foreach($priority as $row){
        $id=trim((string)($row['profile_id']??''));if($id===''||isset($seen[$id]))continue;
        $seen[$id]=true;$merged[]=$row;
    }
    foreach($ranked as $row){
        $id=trim((string)($row['profile_id']??''));if($id===''||isset($seen[$id]))continue;
        $seen[$id]=true;$merged[]=$row;
    }
    return $merged;
}

function p50_cf4_prioritize_top_ranked(array $ranked,int $topN=P50_CONTENT_FRESHNESS_V4_TOP_RANK_FORCE): array {
    $topN=max(1,min(10,$topN));$top=[];$rest=[];$seen=[];
    foreach($ranked as $row){
        $pos=(int)($row['rank_position']??9999);
        if($pos>=1&&$pos<=$topN)$top[]=$row;else $rest[]=$row;
    }
    usort($top,static fn($a,$b)=>(int)($a['rank_position']??9999)<=> (int)($b['rank_position']??9999));
    $merged=[];
    foreach(array_merge($top,$rest) as $row){
        $id=trim((string)($row['profile_id']??''));if($id===''||isset($seen[$id]))continue;
        $seen[$id]=true;$merged[]=$row;
    }
    return ['rows'=>$merged,'topCount'=>count($top)];
}

function p50_cf4_prioritize_tiktok_oauth(PDO $pdo,array $ranked,int $limit=P50_CONTENT_FRESHNESS_V4_TIKTOK_OAUTH_LIMIT): array {
    if(!function_exists('p50tm_authorized_profile_ids'))return ['rows'=>$ranked,'count'=>0];
    $authorized=array_slice(array_values(array_unique(array_filter(array_map('strval',p50tm_authorized_profile_ids($pdo))))),0,max(0,$limit));
    if(!$authorized)return ['rows'=>$ranked,'count'=>0];
    $byId=[];foreach($ranked as $row){$id=trim((string)($row['profile_id']??''));if($id!=='')$byId[$id]=$row;}
    $priority=[];foreach($authorized as $id){$priority[]=$byId[$id]??['profile_id'=>$id,'rank_position'=>0,'latest_content'=>null];unset($byId[$id]);}
    $remaining=[];foreach($ranked as $row){$id=trim((string)($row['profile_id']??''));if($id!==''&&isset($byId[$id])){$remaining[]=$row;unset($byId[$id]);}}
    return ['rows'=>array_merge($priority,$remaining),'count'=>count($priority)];
}

function p50_cf4_platform_counter(array &$counter,string $platform,string $key,int $increment=1): void {
    if(!isset($counter[$platform]))$counter[$platform]=[];
    $counter[$platform][$key]=(int)($counter[$platform][$key]??0)+$increment;
}

function p50_cf4_authorized_rows(PDO $pdo,array $ranked,array $xPolicy): array {
    $ids=array_values(array_filter(array_map(static fn($row)=>trim((string)($row['profile_id']??'')),$ranked)));
    $summary=['verified'=>0,'enabled'=>0,'authorized'=>0,'disabled'=>0,'configurationMissing'=>0,'authorizationRequired'=>0,'unsupported'=>0,'paymentRequiredPaused'=>0];$byPlatform=[];
    if(!$ids)return ['rows'=>[],'summary'=>$summary,'byPlatform'=>$byPlatform];
    $placeholders=implode(',',array_fill(0,count($ids),'?'));$threshold=p50_mc_threshold();
    $stmt=$pdo->prepare("SELECT r.profile_id,s.platform FROM p50_profile_registry r JOIN p50_social_links s ON BINARY s.profile_id=BINARY r.profile_id WHERE r.alive=1 AND r.profile_id IN ($placeholders) AND s.status='verified' AND s.confidence>=? AND s.platform IN ('YouTube','X','TikTok','Instagram','Facebook','Snapchat')");
    $stmt->execute([...$ids,$threshold]);
    $rows=p50_mo_unique_candidate_rows(array_merge($stmt->fetchAll(),p50_mo_oauth_youtube_rows($pdo,$ids),p50_mo_oauth_meta_rows($pdo,$ids)));$summary['verified']=count($rows);
    $seenStmt=$pdo->prepare("SELECT profile_id,platform,MAX(last_seen_at) last_seen_at FROM p50_metric_contents WHERE status='active' AND profile_id IN ($placeholders) GROUP BY profile_id,platform");
    $seenStmt->execute($ids);$seen=[];foreach($seenStmt->fetchAll() as $row)$seen[(string)$row['profile_id'].'|'.(string)$row['platform']]=$row['last_seen_at']?:null;
    $authorized=[];
    foreach($rows as $row){
        $profileId=(string)$row['profile_id'];$platform=p50_mc_platform((string)$row['platform']);p50_cf4_platform_counter($byPlatform,$platform,'verified');
        if(!p50_mc_platform_enabled($platform)){$summary['disabled']++;p50_cf4_platform_counter($byPlatform,$platform,'disabled');continue;}
        $summary['enabled']++;p50_cf4_platform_counter($byPlatform,$platform,'enabled');
        if($platform==='X'&&empty($xPolicy['enabled'])){$summary['paymentRequiredPaused']++;p50_cf4_platform_counter($byPlatform,$platform,'paymentRequiredPaused');continue;}
        $access=p50_mc_public_access($platform,$profileId);$mode=(string)($access['mode']??'');
        if($mode==='unsupported_account_type'){$summary['unsupported']++;p50_cf4_platform_counter($byPlatform,$platform,'unsupported');continue;}
        if(empty($access['configured'])){$summary['configurationMissing']++;p50_cf4_platform_counter($byPlatform,$platform,'configurationMissing');continue;}
        if(empty($access['authorized'])){$summary['authorizationRequired']++;p50_cf4_platform_counter($byPlatform,$platform,'authorizationRequired');continue;}
        $summary['authorized']++;p50_cf4_platform_counter($byPlatform,$platform,'authorized');$authorized[]=['profileId'=>$profileId,'platform'=>$platform,'lastContentAt'=>$seen[$profileId.'|'.$platform]??null];
    }
    ksort($byPlatform,SORT_NATURAL|SORT_FLAG_CASE);return ['rows'=>$authorized,'summary'=>$summary,'byPlatform'=>$byPlatform];
}

function p50_cf4_select(array $ranked,array $authorized,int $profileLimit=P50_CONTENT_FRESHNESS_V4_PROFILE_LIMIT,int $jobLimit=P50_CONTENT_FRESHNESS_V4_JOB_LIMIT): array {
    $byProfile=[];foreach($authorized as $row)$byProfile[(string)$row['profileId']][]=$row;
    $selectedProfiles=[];$selectedJobs=[];$secondaries=[];$loads=[];
    foreach($ranked as $rankRow){
        if(count($selectedProfiles)>=$profileLimit)break;$profileId=(string)($rankRow['profile_id']??'');$options=$byProfile[$profileId]??[];if(!$options)continue;
        usort($options,static function($a,$b) use(&$loads){$at=trim((string)($a['lastContentAt']??''));$bt=trim((string)($b['lastContentAt']??''));if(($at==='')!==($bt===''))return $at===''?-1:1;if($at!==$bt)return strcmp($at,$bt);$load=(int)($loads[$a['platform']]??0)<=>(int)($loads[$b['platform']]??0);return $load!==0?$load:strcmp((string)$a['platform'],(string)$b['platform']);});
        $primary=array_shift($options);$primary['rankPosition']=(int)($rankRow['rank_position']??0);$primary['role']='primary';$selectedJobs[]=$primary;$selectedProfiles[$profileId]=true;$loads[$primary['platform']]=(int)($loads[$primary['platform']]??0)+1;
        foreach($options as $option){$option['rankPosition']=(int)($rankRow['rank_position']??0);$option['role']='secondary';$secondaries[]=$option;}
    }
    usort($secondaries,static function($a,$b) use(&$loads){$load=(int)($loads[$a['platform']]??0)<=>(int)($loads[$b['platform']]??0);if($load!==0)return $load;$at=trim((string)($a['lastContentAt']??''));$bt=trim((string)($b['lastContentAt']??''));if(($at==='')!==($bt===''))return $at===''?-1:1;if($at!==$bt)return strcmp($at,$bt);return (int)$a['rankPosition']<=>(int)$b['rankPosition'];});
    foreach($secondaries as $row){if(count($selectedJobs)>=$jobLimit)break;$selectedJobs[]=$row;$loads[$row['platform']]=(int)($loads[$row['platform']]??0)+1;}
    $platforms=[];foreach($selectedJobs as $row)p50_cf4_platform_counter($platforms,(string)$row['platform'],'selected');ksort($platforms,SORT_NATURAL|SORT_FLAG_CASE);
    return ['profiles'=>array_keys($selectedProfiles),'jobs'=>$selectedJobs,'platforms'=>$platforms];
}

function p50_cf4_select_all(array $ranked,array $authorized,int $jobLimit=140): array {
    $byProfile=[];foreach($authorized as $row)$byProfile[(string)$row['profileId']][]=$row;
    $selectedProfiles=[];$selectedJobs=[];$loads=[];
    foreach($ranked as $rankRow){
        $profileId=(string)($rankRow['profile_id']??'');$options=$byProfile[$profileId]??[];if(!$options)continue;
        usort($options,static function($a,$b) use(&$loads){$at=trim((string)($a['lastContentAt']??''));$bt=trim((string)($b['lastContentAt']??''));if(($at==='')!==($bt===''))return $at===''?-1:1;if($at!==$bt)return strcmp($at,$bt);$load=(int)($loads[$a['platform']]??0)<=>(int)($loads[$b['platform']]??0);return $load!==0?$load:strcmp((string)$a['platform'],(string)$b['platform']);});
        foreach($options as $option){
            if(count($selectedJobs)>=$jobLimit)break 2;
            $option['rankPosition']=(int)($rankRow['rank_position']??0);$option['role']=empty($selectedProfiles[$profileId])?'primary':'secondary';
            $selectedJobs[]=$option;$selectedProfiles[$profileId]=true;$loads[$option['platform']]=(int)($loads[$option['platform']]??0)+1;
        }
    }
    $platforms=[];foreach($selectedJobs as $row)p50_cf4_platform_counter($platforms,(string)$row['platform'],'selected');ksort($platforms,SORT_NATURAL|SORT_FLAG_CASE);
    return ['profiles'=>array_keys($selectedProfiles),'jobs'=>$selectedJobs,'platforms'=>$platforms];
}

function p50_cf4_enqueue(PDO $pdo,array $row,string $dispatchId,int $now,string $reason='content_freshness_v4'): array {
    $profileId=(string)$row['profileId'];$platform=(string)$row['platform'];$start=(int)(floor($now/P50_CONTENT_FRESHNESS_V4_BUCKET_SECONDS)*P50_CONTENT_FRESHNESS_V4_BUCKET_SECONDS);$bucket=gmdate('YmdHis',$start);$observedAt=gmdate('Y-m-d H:i:s',$now);
    $idempotency=hash('sha256',implode('|',[P50_CONTENT_FRESHNESS_V4_VERSION,$bucket,$profileId,$platform,$reason]));
    $payload=['profileId'=>$profileId,'platform'=>$platform,'contentLimit'=>6,'observedAt'=>$observedAt,'liveConfirmed'=>false,'cadence'=>'p0','bucket'=>$bucket,'dispatchId'=>$dispatchId,'reason'=>$reason];
    return p50_metrics_enqueue_job($pdo,['idempotencyKey'=>$idempotency,'collector'=>strtolower($platform).'_v1','platform'=>$platform,'scopeType'=>'profile','scopeId'=>$profileId,'priority'=>5,'maxAttempts'=>3,'payload'=>$payload])+['platform'=>$platform,'bucket'=>$bucket];
}

function p50_cf4_execute(PDO $pdo,string $dispatchId,array $options=[]): array {
    $started=microtime(true);$stage='bootstrap';
    $rankedLimit=max(8,min(150,(int)($options['rankedLimit']??70)));
    $profileLimit=max(1,min(150,(int)($options['profileLimit']??P50_CONTENT_FRESHNESS_V4_PROFILE_LIMIT)));
    $jobLimit=max(1,min(200,(int)($options['jobLimit']??P50_CONTENT_FRESHNESS_V4_JOB_LIMIT)));
    $maxIterations=max(1,min(200,(int)($options['maxIterations']??P50_CONTENT_FRESHNESS_V4_WORK_ITERATIONS)));
    $timeBudgetMs=max(0,min(120000,(int)($options['timeBudgetMs']??0)));
    $mode=(string)($options['mode']??'cycle');
    $enqueueReason=(string)($options['enqueueReason']??'content_freshness_v4');
    $forceTopN=max(0,min(10,(int)($options['forceTopN']??P50_CONTENT_FRESHNESS_V4_TOP_RANK_FORCE)));
    $workOnly=$mode==='work';
    try{
        p50_metrics_ensure_schema($pdo);p50_metrics_recover_stale_jobs($pdo);$xPolicy=p50_cf4_x_policy();
        $ranked=[];$selection=['profiles'=>[],'jobs'=>[],'platforms'=>[]];$access=['summary'=>['verified'=>0,'authorized'=>0],'byPlatform'=>[]];$priority=['count'=>0,'rows'=>[]];$topRankedByPeriod=[];$topRankedCount=0;$enqueued=0;$duplicates=0;$enqueueByPlatform=[];
        if(!$workOnly){
            $stage='selection';
            $ranked=p50_cf4_ranked_profiles($pdo,$rankedLimit);
            if($forceTopN>0){
                $topPeriodMeta=p50_cf4_top_profiles_all_periods($pdo,$forceTopN);
                if($topPeriodMeta['count']>0){
                    $ranked=p50_cf4_merge_ranked_lists($topPeriodMeta['rows'],$ranked);
                    $topRankedCount=(int)$topPeriodMeta['count'];
                    $topRankedByPeriod=$topPeriodMeta['periods'];
                }else{
                    $topPriority=p50_cf4_prioritize_top_ranked($ranked,$forceTopN);
                    $ranked=$topPriority['rows'];
                    $topRankedCount=(int)$topPriority['topCount'];
                }
            }
            $priority=p50_cf4_prioritize_tiktok_oauth($pdo,$ranked);$ranked=$priority['rows'];
            $access=p50_cf4_authorized_rows($pdo,$ranked,$xPolicy);
            $selection=$mode==='all'?p50_cf4_select_all($ranked,$access['rows'],$jobLimit):p50_cf4_select($ranked,$access['rows'],$profileLimit,$jobLimit);
            $stage='enqueue';$now=time();
            foreach($selection['jobs'] as $row){$job=p50_cf4_enqueue($pdo,$row,$dispatchId,$now,$enqueueReason);$platform=(string)$row['platform'];if(!empty($job['created'])){$enqueued++;p50_cf4_platform_counter($enqueueByPlatform,$platform,'enqueued');}else{$duplicates++;p50_cf4_platform_counter($enqueueByPlatform,$platform,'duplicates');}}
        }
        $stage='work';$processed=0;$completed=0;$partial=0;$failed=0;$retried=0;$skipped=0;$processedByPlatform=[];$budgetHit=false;
        for($iteration=1;$iteration<=$maxIterations;$iteration++){
            if($timeBudgetMs>0&&((microtime(true)-$started)*1000)>=$timeBudgetMs){$budgetHit=true;break;}
            $remaining=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=5 AND status IN ('pending','running','retry_wait')");if($remaining===0)break;
            $work=p50_metrics_process_next_job($pdo);if(empty($work['processed']))break;
            $processed+=(int)($work['processed']??0);$completed+=(int)($work['completed']??0);$partial+=(int)($work['partial']??0);$failed+=(int)($work['failed']??0);$retried+=(int)($work['retried']??0);$skipped+=(int)($work['skipped']??0);
            $jobStmt=$pdo->prepare("SELECT platform,priority FROM p50_metric_jobs WHERE job_uuid=? LIMIT 1");$jobStmt->execute([(string)$work['jobUuid']]);$jobRow=$jobStmt->fetch()?:[];
            if((int)($jobRow['priority']??-1)===5){$platform=p50_mc_platform((string)($jobRow['platform']??'Unknown'));p50_cf4_platform_counter($processedByPlatform,$platform,'processed');p50_cf4_platform_counter($processedByPlatform,$platform,(string)($work['status']??'unknown'));}
        }
        $remaining=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=5 AND status IN ('pending','running','retry_wait')");
        $refresh=null;
        if($remaining===0||!empty($options['forceRefresh'])){
            $stage='content_intelligence';$refresh=p50_ci_refresh($pdo);
        }
        ksort($enqueueByPlatform,SORT_NATURAL|SORT_FLAG_CASE);ksort($processedByPlatform,SORT_NATURAL|SORT_FLAG_CASE);
        $action=$workOnly?'refresh_work':($mode==='all'?'refresh_all':'refresh');
        return ['ok'=>true,'action'=>$action,'version'=>P50_CONTENT_FRESHNESS_V4_VERSION,'dispatchId'=>$dispatchId,'bucketSeconds'=>P50_CONTENT_FRESHNESS_V4_BUCKET_SECONDS,'facebookCollectorVersion'=>defined('P50_FACEBOOK_COLLECTOR_VERSION')?P50_FACEBOOK_COLLECTOR_VERSION:null,'xFastCycle'=>$xPolicy,'profilesScanned'=>count($ranked),'profilesSelected'=>count($selection['profiles']),'topRankedPrioritized'=>$topRankedCount,'topRankedByPeriod'=>$topRankedByPeriod,'tiktokOauthProfilesPrioritized'=>(int)$priority['count'],'candidateLinks'=>(int)$access['summary']['verified'],'authorizedLinks'=>(int)$access['summary']['authorized'],'accessSummary'=>$access['summary'],'accessByPlatform'=>$access['byPlatform'],'selectedByPlatform'=>$selection['platforms'],'enqueueByPlatform'=>$enqueueByPlatform,'processedByPlatform'=>$processedByPlatform,'enqueued'=>$enqueued,'duplicates'=>$duplicates,'processed'=>$processed,'completed'=>$completed,'partial'=>$partial,'retried'=>$retried,'failed'=>$failed,'skipped'=>$skipped,'remaining'=>$remaining,'continue'=>$remaining>0,'budgetHit'=>$budgetHit,'contentIntelligence'=>$refresh,'stage'=>$remaining>0?'work':'complete','durationMs'=>(int)round((microtime(true)-$started)*1000),'publicStateWrites'=>0];
    }catch(Throwable $error){
        $detail=p50_metrics_safe_error($error->getMessage());error_log('PASS50 content freshness ['.$stage.']: '.$detail);
        return ['ok'=>false,'error'=>'Rafraîchissement rapide des contenus interrompu.','errorCode'=>'content_freshness_'.$stage,'detail'=>$detail,'stage'=>$stage,'dispatchId'=>$dispatchId,'version'=>P50_CONTENT_FRESHNESS_V4_VERSION,'facebookCollectorVersion'=>defined('P50_FACEBOOK_COLLECTOR_VERSION')?P50_FACEBOOK_COLLECTOR_VERSION:null,'publicStateWrites'=>0,'durationMs'=>(int)round((microtime(true)-$started)*1000)];
    }
}
