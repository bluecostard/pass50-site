<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-collectors-core.php';

const P50_METRICS_ORCHESTRATOR_VERSION='1.0.0';
const P50_METRICS_ORCHESTRATOR_COLLECTOR='metrics_orchestrator_v1';
const P50_METRICS_CLAIM_LOCK='pass50_metrics_job_claim_v1';

function p50_mo_config(): array {
    global $config;$m=(array)($config['metrics']??[]);
    $secret=trim((string)($m['cron_secret']??(getenv('PASS50_METRICS_CRON_SECRET')?:'')));
    return [
      'enabled'=>(bool)($m['orchestrator_enabled']??false),'cronSecret'=>$secret,
      'p0Max'=>max(1,min(100,(int)($m['p0_max_profiles']??20))),
      'p1Max'=>max(1,min(500,(int)($m['p1_max_profiles']??100))),
      'p1Rank'=>max(50,min(200,(int)($m['p1_max_rank']??70))),
      'p2Max'=>max(1,min(1000,(int)($m['p2_max_profiles']??500))),
      'priorityIds'=>array_values(array_unique(array_filter(array_map('strval',(array)($m['priority_profile_ids']??[]))))),
      'fresh'=>['p0'=>max(1,(int)($m['p0_min_freshness_minutes']??12)),'p1'=>max(1,(int)($m['p1_min_freshness_minutes']??90)),'p2'=>max(1,(int)($m['p2_min_freshness_minutes']??600))],
      'lockTimeout'=>max(2,min(60,(int)($m['worker_lock_timeout_minutes']??10))),
    ];
}

function p50_mo_cadence(string $value): array {
    $key=strtolower(trim($value));return match($key){
      'p0'=>['key'=>'p0','name'=>'priority','seconds'=>900,'priority'=>10,'contentLimit'=>3,'trigger'=>'scheduled_p0','dispatchTrigger'=>'dispatch_p0'],
      'p1'=>['key'=>'p1','name'=>'top50','seconds'=>7200,'priority'=>50,'contentLimit'=>5,'trigger'=>'scheduled_p1','dispatchTrigger'=>'dispatch_p1'],
      'p2'=>['key'=>'p2','name'=>'census','seconds'=>43200,'priority'=>100,'contentLimit'=>5,'trigger'=>'scheduled_p2','dispatchTrigger'=>'dispatch_p2'],
      default=>throw new InvalidArgumentException('Cadence inconnue.'),
    };
}

function p50_mo_bucket(array $cadence,?string $now=null): array {
    $timestamp=strtotime($now??gmdate('c'));if($timestamp===false)throw new InvalidArgumentException('Date de dispatch invalide.');
    $start=(int)(floor($timestamp/$cadence['seconds'])*$cadence['seconds']);
    return ['key'=>gmdate('YmdHis',$start),'observedAt'=>gmdate('Y-m-d H:i:s',$timestamp),'startsAt'=>gmdate('c',$start)];
}

function p50_mo_next_expected(string $cadenceKey,?int $now=null): string {
    $now=$now??time();$hour=(int)gmdate('G',$now);
    if($cadenceKey==='p0')$next=((int)floor($now/900)+1)*900;
    elseif($cadenceKey==='p1'){
        $base=gmmktime($hour,7,0,(int)gmdate('n',$now),(int)gmdate('j',$now),(int)gmdate('Y',$now));
        if($hour%2!==0)$base-=3600;if($base<=$now)$base+=7200;$next=$base;
    }else{
        $slot=$hour<12?0:12;$base=gmmktime($slot,23,0,(int)gmdate('n',$now),(int)gmdate('j',$now),(int)gmdate('Y',$now));
        if($base<=$now)$base+=43200;$next=$base;
    }
    return gmdate('c',$next);
}

function p50_mo_live_profiles(PDO $pdo): array {
    if(!p50_metrics_table_exists($pdo,'p50_live_streams'))return ['status'=>'unavailable','profileIds'=>[],'source'=>'p50_live_streams'];
    global $config;$stale=max(5,min(180,(int)($config['data_engine']['live_stale_minutes']??45)));
    $stmt=$pdo->query("SELECT DISTINCT profile_id FROM p50_live_streams WHERE status='live' AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$stale." MINUTE) AND profile_id IS NOT NULL LIMIT 100");
    return ['status'=>'available','profileIds'=>array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)),'source'=>'p50_live_streams'];
}

function p50_mo_viral_profiles(PDO $pdo): array {
    $stmt=$pdo->query("SELECT profile_id,platform,account_id,content_id,views,followers,observed_at FROM p50_metric_captures
      WHERE quality_status='usable' AND observed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 HOUR)
      AND (views IS NOT NULL OR followers IS NOT NULL) ORDER BY profile_id,platform,account_id,content_id,observed_at DESC,id DESC LIMIT 2000");
    $series=[];foreach($stmt->fetchAll() as $row){$key=$row['profile_id'].'|'.$row['platform'].'|'.$row['account_id'].'|'.($row['content_id']??'account');if(count($series[$key]??[])<2)$series[$key][]=$row;}
    $ids=[];foreach($series as $rows)if(count($rows)===2){
        foreach(['views','followers'] as $metric){$new=$rows[0][$metric]??null;$old=$rows[1][$metric]??null;if($new!==null&&$old!==null&&(int)$new>(int)$old&&((int)$old===0||(int)$new/(int)$old>=1.5)){$ids[(string)$rows[0]['profile_id']]=true;break;}}
    }return array_keys($ids);
}

function p50_mo_candidate_ids(PDO $pdo,array $cadence,array $live,array $cfg): array {
    if($cadence['key']==='p0')return array_values(array_unique(array_merge($live['profileIds'],p50_mo_viral_profiles($pdo),$cfg['priorityIds'])));
    if($cadence['key']==='p1'){
        if(p50_metrics_table_exists($pdo,'p50_ranking_snapshots')){
            $stmt=$pdo->prepare("SELECT r.profile_id FROM p50_profile_registry r JOIN p50_ranking_snapshots s ON s.profile_id=r.profile_id
              JOIN (SELECT profile_id,MAX(captured_at) captured_at FROM p50_ranking_snapshots GROUP BY profile_id) latest
                ON latest.profile_id=s.profile_id AND latest.captured_at=s.captured_at
              WHERE r.alive=1 AND s.rank_position BETWEEN 1 AND ? ORDER BY s.rank_position LIMIT ?");
        }elseif(p50_metrics_column_exists($pdo,'p50_profile_registry','rank_position')){
            $stmt=$pdo->prepare("SELECT profile_id FROM p50_profile_registry WHERE alive=1 AND rank_position BETWEEN 1 AND ? ORDER BY rank_position LIMIT ?");
        }else return [];
        $stmt->bindValue(1,$cfg['p1Rank'],PDO::PARAM_INT);$stmt->bindValue(2,$cfg['p1Max'],PDO::PARAM_INT);$stmt->execute();return array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $stmt=$pdo->prepare("SELECT profile_id FROM p50_profile_registry WHERE alive=1 ORDER BY profile_id LIMIT ?");$stmt->bindValue(1,$cfg['p2Max'],PDO::PARAM_INT);$stmt->execute();
    return array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
}

function p50_mo_unique_candidate_rows(array $rows): array {
    $unique=[];
    foreach($rows as $row){
        $profileId=trim((string)($row['profile_id']??''));$platform=p50_mc_platform((string)($row['platform']??''));
        if($profileId===''||$platform==='')continue;
        $unique[$profileId.'|'.$platform]=['profile_id'=>$profileId,'platform'=>$platform];
    }
    return array_values($unique);
}

function p50_mo_oauth_youtube_rows(PDO $pdo,array $profileIds): array {
    if(!$profileIds||!p50_metrics_table_exists($pdo,'p50_youtube_oauth_connections')||!p50_metrics_column_exists($pdo,'p50_youtube_oauth_connections','profile_id'))return [];
    $placeholders=implode(',',array_fill(0,count($profileIds),'?'));
    $stmt=$pdo->prepare("SELECT DISTINCT r.profile_id,'YouTube' platform FROM p50_profile_registry r JOIN p50_youtube_oauth_connections y ON BINARY y.profile_id=BINARY r.profile_id WHERE r.alive=1 AND r.profile_id IN ($placeholders) AND y.profile_id IS NOT NULL AND y.status='active'");
    $stmt->execute($profileIds);
    return $stmt->fetchAll();
}

function p50_mo_enqueue_profile(PDO $pdo,string $profileId,string $platform,string $cadenceKey='p0',array $options=[]): array {
    $profileId=trim($profileId);$platform=p50_mc_platform($platform);
    if($profileId===''||$platform==='')throw new InvalidArgumentException('Profil ou plateforme métrique invalide.');
    p50_metrics_ensure_schema($pdo);
    $cadence=p50_mo_cadence($cadenceKey);$bucket=p50_mo_bucket($cadence,$options['now']??null);
    $priority=max(0,min(1000,(int)($options['priorityOverride']??$cadence['priority'])));
    $reason=trim((string)($options['reason']??'manual'))?:'manual';
    $idempotency=hash('sha256',implode('|',[P50_METRICS_ORCHESTRATOR_VERSION,'profile_enqueue',$bucket['key'],$profileId,$platform,$reason]));
    $payload=['profileId'=>$profileId,'platform'=>$platform,'contentLimit'=>(int)($options['contentLimit']??$cadence['contentLimit']),'observedAt'=>$bucket['observedAt'],'liveConfirmed'=>false,'cadence'=>$cadenceKey,'bucket'=>$bucket['key'],'dispatchId'=>substr((string)($options['dispatchId']??$reason),0,120),'reason'=>$reason];
    return p50_metrics_enqueue_job($pdo,['idempotencyKey'=>$idempotency,'collector'=>strtolower($platform).'_v1','platform'=>$platform,'scopeType'=>'profile','scopeId'=>$profileId,'priority'=>$priority,'maxAttempts'=>3,'payload'=>$payload])+['cadence'=>$cadenceKey,'profileId'=>$profileId,'platform'=>$platform,'priority'=>$priority,'bucket'=>$bucket];
}

function p50_mo_select(PDO $pdo,string $cadenceKey,array $options=[]): array {
    $cadence=p50_mo_cadence($cadenceKey);$cfg=p50_mo_config();$bucket=p50_mo_bucket($cadence,$options['now']??null);$live=p50_mo_live_profiles($pdo);
    $ids=p50_mo_candidate_ids($pdo,$cadence,$live,$cfg);$max=$cadence['key']==='p0'?$cfg['p0Max']:($cadence['key']==='p1'?$cfg['p1Max']:$cfg['p2Max']);$ids=array_slice($ids,0,$max);
    $summary=['eligibleProfiles'=>0,'eligibleLinks'=>0,'jobsCreated'=>0,'duplicateJobs'=>0,'skippedFresh'=>0,'skippedConfiguration'=>0,'skippedAuthRequired'=>0,'skippedUnsupported'=>0];
    if(!$ids)return compact('cadence','bucket','live','summary')+['candidates'=>[]];
    $placeholders=implode(',',array_fill(0,count($ids),'?'));$threshold=p50_mc_threshold();
    $stmt=$pdo->prepare("SELECT r.profile_id,s.platform FROM p50_profile_registry r JOIN p50_social_links s ON s.profile_id=r.profile_id
      WHERE r.alive=1 AND r.profile_id IN ($placeholders) AND s.status='verified' AND s.confidence>=?
      AND s.platform IN ('YouTube','X','TikTok','Instagram','Facebook','Snapchat') ORDER BY r.profile_id,s.platform LIMIT 3000");
    $stmt->execute([...$ids,$threshold]);$rows=p50_mo_unique_candidate_rows(array_merge($stmt->fetchAll(),p50_mo_oauth_youtube_rows($pdo,$ids)));$summary['eligibleProfiles']=count(array_unique(array_column($rows,'profile_id')));$summary['eligibleLinks']=count($rows);$candidates=[];$liveSet=array_fill_keys($live['profileIds'],true);
    $selectionTime=strtotime((string)($options['now']??'now'));if($selectionTime===false)$selectionTime=time();
    foreach($rows as $row){$profileId=(string)$row['profile_id'];$platform=(string)$row['platform'];$access=p50_mc_public_access($platform,$profileId);
        if(!$access['configured']){$summary['skippedConfiguration']++;continue;}if(!$access['authorized']){$summary['skippedAuthRequired']++;continue;}
        if(($access['mode']??'')==='unsupported_account_type'){$summary['skippedUnsupported']++;continue;}
        $fresh=$pdo->prepare("SELECT MAX(captured_at) FROM p50_metric_captures WHERE profile_id=? AND platform=? AND quality_status='usable'");$fresh->execute([$profileId,$platform]);$last=$fresh->fetchColumn();
        $liveConfirmed=$cadence['key']==='p0'&&isset($liveSet[$profileId]);if(!$liveConfirmed&&$last&&strtotime((string)$last)>=$selectionTime-$cfg['fresh'][$cadence['key']]*60){$summary['skippedFresh']++;continue;}
        if($cadence['priority']>10){$higher=$pdo->prepare("SELECT COUNT(*) FROM p50_metric_jobs WHERE scope_type='profile' AND scope_id=? AND platform=? AND priority<? AND status IN ('pending','running','retry_wait')");$higher->execute([$profileId,$platform,$cadence['priority']]);if((int)$higher->fetchColumn()>0){$summary['skippedFresh']++;continue;}}
        $idempotency=hash('sha256',implode('|',[P50_METRICS_ORCHESTRATOR_VERSION,$cadence['key'],$bucket['key'],$profileId,$platform]));
        $candidates[]=['profileId'=>$profileId,'platform'=>$platform,'contentLimit'=>$cadence['contentLimit'],'observedAt'=>$bucket['observedAt'],'liveConfirmed'=>$liveConfirmed,'idempotencyKey'=>$idempotency];
    }
    return compact('cadence','bucket','live','summary','candidates');
}

function p50_metrics_recover_stale_jobs(PDO $pdo,?int $minutes=null): array {
    $minutes=$minutes??p50_mo_config()['lockTimeout'];$minutes=max(1,min(120,$minutes));
    $retry=$pdo->prepare("UPDATE p50_metric_jobs SET status='retry_wait',next_attempt_at=UTC_TIMESTAMP(),locked_at=NULL,lock_token=NULL,last_error='Tâche interrompue récupérée automatiquement'
      WHERE status='running' AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$minutes." MINUTE) AND attempts<max_attempts");$retry->execute();
    $failed=$pdo->prepare("UPDATE p50_metric_jobs SET status='failed',next_attempt_at=NULL,locked_at=NULL,lock_token=NULL,last_error='Tâche interrompue après le maximum de tentatives'
      WHERE status='running' AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$minutes." MINUTE) AND attempts>=max_attempts");$failed->execute();
    return ['retried'=>$retry->rowCount(),'failed'=>$failed->rowCount()];
}

function p50_mo_dispatch(PDO $pdo,string $cadenceKey,string $dispatchId,array $options=[]): array {
    $preview=!empty($options['preview']);if(!$preview)p50_metrics_recover_stale_jobs($pdo);$cfg=p50_mo_config();if(!$preview&&!$cfg['enabled'])throw new RuntimeException('Orchestrateur métrique désactivé.');
    $source=in_array(($options['source']??''),['cron_hmac','admin'],true)?(string)$options['source']:'server';
    $started=microtime(true);$selection=p50_mo_select($pdo,$cadenceKey,$options);$summary=$selection['summary'];$run=null;
    if(!$preview)$run=p50_metrics_start_run($pdo,['collector'=>P50_METRICS_ORCHESTRATOR_COLLECTOR,'triggerType'=>$selection['cadence']['dispatchTrigger'],'metadata'=>['cadence'=>$cadenceKey,'bucket'=>$selection['bucket']['key'],'dispatchId'=>substr($dispatchId,0,120),'source'=>$source]]);
    try{foreach($selection['candidates'] as $candidate){
        if($preview)continue;$payload=$candidate+['cadence'=>$cadenceKey,'bucket'=>$selection['bucket']['key'],'dispatchId'=>substr($dispatchId,0,120)];
        $job=p50_metrics_enqueue_job($pdo,['idempotencyKey'=>$candidate['idempotencyKey'],'collector'=>strtolower($candidate['platform']).'_v1','platform'=>$candidate['platform'],'scopeType'=>'profile','scopeId'=>$candidate['profileId'],'priority'=>$selection['cadence']['priority'],'maxAttempts'=>3,'payload'=>$payload]);
        $summary[$job['created']?'jobsCreated':'duplicateJobs']++;
    }}catch(Throwable $error){
        if($run)p50_metrics_finish_run($pdo,$run['runUuid'],'error',['errorCount'=>1],$error->getMessage(),['cadence'=>$cadenceKey,'bucket'=>$selection['bucket']['key'],'dispatchId'=>substr($dispatchId,0,120),'source'=>$source,'jobsCreated'=>$summary['jobsCreated']]);
        throw $error;
    }
    $depth=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE status IN ('pending','running','retry_wait')");
    $duration=(int)round((microtime(true)-$started)*1000);if($run)p50_metrics_finish_run($pdo,$run['runUuid'],'success',['accountsProcessed'=>$summary['eligibleProfiles'],'contentsFound'=>$summary['eligibleLinks']],null,[
      'cadence'=>$cadenceKey,'bucket'=>$selection['bucket']['key'],'dispatchId'=>substr($dispatchId,0,120),'source'=>$source,'candidates'=>count($selection['candidates']),'jobsCreated'=>$summary['jobsCreated'],'duplicateJobs'=>$summary['duplicateJobs'],
      'skippedFresh'=>$summary['skippedFresh'],'skippedConfiguration'=>$summary['skippedConfiguration'],'skippedAuthRequired'=>$summary['skippedAuthRequired'],'liveSource'=>$selection['live']['status'],'durationMs'=>$duration,'queueDepthAfter'=>$depth]);
    return ['ok'=>true,'cadence'=>$cadenceKey,'dispatchId'=>$dispatchId,'bucket'=>$selection['bucket'],'summary'=>$summary,'candidates'=>$preview?$selection['candidates']:[],'enqueued'=>$summary['jobsCreated'],'remaining'=>$depth,'liveSourceStatus'=>$selection['live']['status'],'durationMs'=>$duration];
}

function p50_mo_verify_cron_signature(string $secret,string $timestamp,string $raw,string $signature,?int $now=null): bool {
    if(strlen($secret)<32||!preg_match('/^\d{10}$/',$timestamp)||abs(($now??time())-(int)$timestamp)>300)return false;
    $signature=strtolower(trim($signature));
    if(!preg_match('/^[a-f0-9]{64}$/',$signature))return false;
    return hash_equals(hash_hmac('sha256',$timestamp."\n".$raw,$secret),$signature);
}

function p50_mo_claim(PDO $pdo): ?array {
    if((int)p50_metrics_value($pdo,"SELECT GET_LOCK(?,2)",[P50_METRICS_CLAIM_LOCK])!==1)return null;
    try{$pdo->beginTransaction();$stmt=$pdo->query("SELECT * FROM p50_metric_jobs WHERE status IN ('pending','retry_wait') AND scheduled_at<=UTC_TIMESTAMP()
        AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()) ORDER BY priority ASC,scheduled_at ASC,id ASC LIMIT 1 FOR UPDATE");$job=$stmt->fetch();
        if(!$job){$pdo->commit();return null;}$token=bin2hex(random_bytes(32));$update=$pdo->prepare("UPDATE p50_metric_jobs SET status='running',locked_at=UTC_TIMESTAMP(),lock_token=?,attempts=attempts+1 WHERE id=? AND status IN ('pending','retry_wait')");
        $update->execute([$token,$job['id']]);$pdo->commit();if($update->rowCount()!==1)return null;$job['lock_token']=$token;$job['attempts']=(int)$job['attempts']+1;return $job;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    finally{try{$release=$pdo->prepare("SELECT RELEASE_LOCK(?)");$release->execute([P50_METRICS_CLAIM_LOCK]);}catch(Throwable){}}
}

function p50_mo_retry_delay(int $attempt,bool $rateLimited=false): int {
    if($rateLimited)return 60;return match($attempt){1=>5,2=>30,default=>120};
}

function p50_mo_transient_result(array $result): bool {
    if(!empty($result['rateLimited'])||($result['status']??'')==='rate_limited')return true;
    $message=strtolower(implode(' ',array_map('strval',(array)($result['errors']??[]))));
    foreach(['timeout','timed out','network','réseau','rate_limited','http 429','http_error','http 5'] as $signal)if(str_contains($message,$signal))return true;
    return false;
}

function p50_mo_finalize(PDO $pdo,array $job,string $status,?string $error=null,?int $delayMinutes=null): bool {
    $next=$delayMinutes!==null?gmdate('Y-m-d H:i:s',time()+$delayMinutes*60):null;
    $stmt=$pdo->prepare("UPDATE p50_metric_jobs SET status=?,next_attempt_at=?,locked_at=NULL,lock_token=NULL,last_error=? WHERE id=? AND lock_token=? AND status='running'");
    $stmt->execute([$status,$next,p50_metrics_safe_error($error),$job['id'],$job['lock_token']]);return $stmt->rowCount()===1;
}

function p50_metrics_process_next_job(PDO $pdo,array $options=[]): array {
    $started=microtime(true);p50_metrics_recover_stale_jobs($pdo);$job=p50_mo_claim($pdo);if(!$job)return ['ok'=>true,'processed'=>0,'remaining'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE status IN ('pending','running','retry_wait')"),'durationMs'=>(int)round((microtime(true)-$started)*1000)];
    $payload=json_decode((string)$job['payload_json'],true);if(!is_array($payload))$payload=[];$result=null;$cadence=null;$profileId='';$platform='';
    try{
        $cadence=p50_mo_cadence((string)($payload['cadence']??''));$profileId=(string)($payload['profileId']??'');$platform=p50_mc_platform((string)($payload['platform']??$job['platform']??''));
        if($profileId===''||$platform==='')throw new InvalidArgumentException('Tâche métrique invalide.');
        $access=p50_mc_public_access($platform,$profileId);if(!$access['configured']){$status='skipped';$error='configuration_missing';}
        elseif(!$access['authorized']){$status='skipped';$error='authorization_required';}
        else{$result=p50_metrics_collect_profile($pdo,$profileId,$platform,(int)($payload['contentLimit']??$cadence['contentLimit']),$options['fetch']??null,(string)$payload['observedAt'],['jobUuid'=>$job['job_uuid'],'triggerType'=>$cadence['trigger'],'cadence'=>$cadence['key']]);
            $collectorStatus=(string)$result['status'];$rateLimited=!empty($result['rateLimited'])||$collectorStatus==='rate_limited';$transient=p50_mo_transient_result($result);
            if($collectorStatus==='success'){$status='completed';$error=null;}
            elseif($collectorStatus==='partial'&&!$transient){$status='completed_partial';$error=$result['errors'][0]??null;}
            elseif(in_array($collectorStatus,['configuration_missing','authorization_required','unsupported_account_type','unavailable_or_blocked'],true)&&!$transient){$status='skipped';$error=$collectorStatus;}
            elseif($transient&&(int)$job['attempts']<(int)$job['max_attempts']){$status='retry_wait';$error=$result['errors'][0]??$collectorStatus;$delay=p50_mo_retry_delay((int)$job['attempts'],$rateLimited);}
            else{$status='failed';$error=$result['errors'][0]??$collectorStatus;}
        }
    }catch(Throwable $throwable){$error=$throwable->getMessage();if((int)$job['attempts']<(int)$job['max_attempts']){$status='retry_wait';$delay=p50_mo_retry_delay((int)$job['attempts']);}else $status='failed';}
    p50_mo_finalize($pdo,$job,$status,$error,$status==='retry_wait'?($delay??5):null);$remaining=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE status IN ('pending','running','retry_wait')");
    return ['ok'=>true,'processed'=>1,'jobUuid'=>$job['job_uuid'],'status'=>$status,'completed'=>(int)($status==='completed'),'partial'=>(int)($status==='completed_partial'),'retried'=>(int)($status==='retry_wait'),'skipped'=>(int)($status==='skipped'),'failed'=>(int)($status==='failed'),'remaining'=>$remaining,'durationMs'=>(int)round((microtime(true)-$started)*1000),'result'=>$result];
}

function p50_mo_status(PDO $pdo): array {
    $cfg=p50_mo_config();$queue=['pending'=>0,'running'=>0,'retry_wait'=>0,'completed'=>0,'completed_partial'=>0,'skipped'=>0,'failed'=>0,'completed24h'=>0];
    $access=[];foreach(['YouTube','X','TikTok','Instagram','Facebook','Snapchat'] as $platform)$access[strtolower($platform)]=p50_mc_public_access($platform,'');
    $empty=['enabled'=>$cfg['enabled'],'cronSecretConfigured'=>strlen($cfg['cronSecret'])>=32,'lastDispatchP0'=>null,'lastDispatchP1'=>null,'lastDispatchP2'=>null,
      'expectedCadences'=>['p0'=>'*/15 * * * * UTC','p1'=>'7 */2 * * * UTC','p2'=>'23 */12 * * * UTC'],
      'nextExpectedAt'=>['p0'=>p50_mo_next_expected('p0'),'p1'=>p50_mo_next_expected('p1'),'p2'=>p50_mo_next_expected('p2')],
      'queue'=>$queue,'staleJobs'=>0,'oldestPendingAt'=>null,'lastWorkerRun'=>null,'excludedAuthorization'=>0,
      'liveSourceStatus'=>p50_mo_live_profiles($pdo)['status'],'configuredPlatforms'=>array_keys(array_filter($access,static fn($a)=>$a['configured'])),'authorizedPlatforms'=>array_keys(array_filter($access,static fn($a)=>$a['authorized']&&($a['mode']??'')!=='unsupported_account_type')),
      'automationObservedRecently'=>false,'summary'=>'Schéma canonique non installé : aucun dispatch automatique observé.'];
    if(!p50_metrics_table_exists($pdo,'p50_metric_jobs')||!p50_metrics_table_exists($pdo,'p50_metric_runs'))return $empty;
    foreach($pdo->query("SELECT status,COUNT(*) total FROM p50_metric_jobs GROUP BY status")->fetchAll() as $row)$queue[(string)$row['status']]=(int)$row['total'];
    $dispatches=[];foreach(['p0','p1','p2'] as $key){$stmt=$pdo->prepare("SELECT run_uuid,status,started_at,finished_at,metadata_json FROM p50_metric_runs WHERE collector=? AND trigger_type=? ORDER BY started_at DESC LIMIT 1");$stmt->execute([P50_METRICS_ORCHESTRATOR_COLLECTOR,'dispatch_'.$key]);$row=$stmt->fetch()?:null;
        if($row){$row['metadata']=json_decode((string)$row['metadata_json'],true)?:[];unset($row['metadata_json']);}$dispatches[$key]=$row;
    }
    $queue['completed24h']=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE status IN ('completed','completed_partial') AND updated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)");
    $lastWorker=p50_metrics_value($pdo,"SELECT MAX(finished_at) FROM p50_metric_runs WHERE job_uuid IS NOT NULL");$recent=p50_metrics_value($pdo,"SELECT MAX(finished_at) FROM p50_metric_runs WHERE collector=? AND trigger_type LIKE 'dispatch_p%' AND status='success' AND metadata_json LIKE '%\"source\":\"cron_hmac\"%'",[P50_METRICS_ORCHESTRATOR_COLLECTOR]);
    $excludedAuthorization=array_sum(array_map(static fn($row): int=>(int)($row['metadata']['skippedAuthRequired']??0),array_filter($dispatches)));
    return ['enabled'=>$cfg['enabled'],'cronSecretConfigured'=>strlen($cfg['cronSecret'])>=32,'lastDispatchP0'=>$dispatches['p0'],'lastDispatchP1'=>$dispatches['p1'],'lastDispatchP2'=>$dispatches['p2'],
      'expectedCadences'=>['p0'=>'*/15 * * * * UTC','p1'=>'7 */2 * * * UTC','p2'=>'23 */12 * * * UTC'],
      'nextExpectedAt'=>['p0'=>p50_mo_next_expected('p0'),'p1'=>p50_mo_next_expected('p1'),'p2'=>p50_mo_next_expected('p2')],'queue'=>$queue,
      'staleJobs'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_jobs WHERE status='running' AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$cfg['lockTimeout']." MINUTE)"),
      'oldestPendingAt'=>p50_metrics_value($pdo,"SELECT MIN(scheduled_at) FROM p50_metric_jobs WHERE status IN ('pending','retry_wait')")?:null,'lastWorkerRun'=>$lastWorker?:null,'excludedAuthorization'=>$excludedAuthorization,
      'liveSourceStatus'=>p50_mo_live_profiles($pdo)['status'],'configuredPlatforms'=>array_keys(array_filter($access,static fn($a)=>$a['configured'])),'authorizedPlatforms'=>array_keys(array_filter($access,static fn($a)=>$a['authorized']&&($a['mode']??'')!=='unsupported_account_type')),
      'automationObservedRecently'=>$recent&&strtotime((string)$recent)>=time()-86400,'summary'=>$recent?'Dernier dispatch automatique observé : '.gmdate('c',strtotime((string)$recent)):'Aucun dispatch automatique récent observé.'];
}
