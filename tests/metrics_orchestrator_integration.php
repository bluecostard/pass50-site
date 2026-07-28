<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/metrics-orchestrator-core.php';
require dirname(__DIR__).'/api/metrics-observability-core.php';

$dsn=getenv('P50_TEST_DSN');if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function orchestrator_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

$cronSecret=hash('sha256','pass50-orchestrator-fixture-secret');
$youtubeKey=hash('sha256','youtube-orchestrator-fixture');
$config=['data_engine'=>['confidence_threshold'=>90,'live_stale_minutes'=>45],'metrics'=>[
  'PASS50_YOUTUBE_API_KEY'=>$youtubeKey,'orchestrator_enabled'=>true,'cron_secret'=>$cronSecret,
  'instagram_enabled'=>true,'instagram_access_token'=>'','facebook_enabled'=>true,'facebook_access_token'=>hash('sha256','facebook-fixture'),'facebook_mode'=>'unsupported_account_type',
  'p0_max_profiles'=>20,'p1_max_profiles'=>100,'p1_max_rank'=>70,'p2_max_profiles'=>500,
  'priority_profile_ids'=>['priority'],'p0_min_freshness_minutes'=>12,'p1_min_freshness_minutes'=>90,
  'p2_min_freshness_minutes'=>600,'worker_lock_timeout_minutes'=>10,
]];
foreach(['p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_ranking_snapshots','p50_live_streams','p50_social_links','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190),alive TINYINT NOT NULL,score DECIMAL(6,2))");
$pdo->exec("CREATE TABLE p50_social_links(profile_id VARCHAR(100),platform VARCHAR(32),normalized_url TEXT,confidence INT,status VARCHAR(24),PRIMARY KEY(profile_id,platform))");
$pdo->exec("CREATE TABLE p50_ranking_snapshots(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),period_key VARCHAR(16),rank_position INT,trend_score DECIMAL(6,2),rank_delta INT,badges TEXT,data_confidence INT,captured_at DATETIME,INDEX(profile_id,captured_at))");
$pdo->exec("CREATE TABLE p50_live_streams(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),status VARCHAR(24),last_seen_at DATETIME)");
$pdo->exec("CREATE TABLE app_state(id INT PRIMARY KEY,state_json LONGTEXT,version INT)");
$pdo->exec("INSERT INTO app_state VALUES(1,'{\"pr5Sentinel\":true}',99)");
$profiles=[
  ['live','Live Fixture',1,50.00,'https://youtube.com/@Live',98,'verified',1],
  ['priority','Priority Fixture',1,49.00,'https://youtube.com/@Priority',98,'verified',60],
  ['near','Near Fixture',1,48.00,'https://youtube.com/@Near',98,'verified',70],
  ['fresh','Fresh Fixture',1,47.00,'https://youtube.com/@Fresh',98,'verified',40],
  ['dead','Dead Fixture',0,46.00,'https://youtube.com/@Dead',98,'verified',5],
  ['low','Low Fixture',1,45.00,'https://youtube.com/@Low',89,'verified',6],
  ['candidate','Candidate Fixture',1,44.00,'https://youtube.com/@Candidate',99,'candidate',7],
];
foreach($profiles as [$id,$name,$alive,$score,$url,$confidence,$status,$rank]){
    $pdo->prepare("INSERT INTO p50_profile_registry VALUES(?,?,?,?)")->execute([$id,$name,$alive,$score]);
    $pdo->prepare("INSERT INTO p50_social_links VALUES(?,?,?,?,?)")->execute([$id,'YouTube',$url,$confidence,$status]);
    $pdo->prepare("INSERT INTO p50_ranking_snapshots(profile_id,period_key,rank_position,trend_score,rank_delta,badges,data_confidence,captured_at) VALUES(?,'current',?,?,0,'[]',99,UTC_TIMESTAMP())")->execute([$id,$rank,$score]);
}
$pdo->exec("INSERT INTO p50_social_links VALUES('near','X','https://x.com/Near',98,'verified'),('near','Instagram','https://instagram.com/near',98,'verified'),('near','Facebook','https://facebook.com/NearPage',98,'verified')");
$pdo->exec("INSERT INTO p50_live_streams(profile_id,status,last_seen_at) VALUES('live','live',UTC_TIMESTAMP()),('dead','live',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR))");
p50_metrics_ensure_schema($pdo);

$p0=p50_mo_select($pdo,'p0');$p0Ids=array_column($p0['candidates'],'profileId');
orchestrator_must(in_array('live',$p0Ids,true)&&in_array('priority',$p0Ids,true),'P0 utilise LIVE récent et liste prioritaire');
$p1=p50_mo_select($pdo,'p1');$p1Ids=array_column($p1['candidates'],'profileId');
orchestrator_must(in_array('live',$p1Ids,true)&&in_array('priority',$p1Ids,true)&&in_array('near',$p1Ids,true),'P1 couvre rangs 1 à 70');
$p2=p50_mo_select($pdo,'p2');$p2Ids=array_column($p2['candidates'],'profileId');
orchestrator_must(!in_array('dead',$p2Ids,true)&&!in_array('low',$p2Ids,true)&&!in_array('candidate',$p2Ids,true),'Census filtre morts, confiance et liens non vérifiés');
orchestrator_must($p2['summary']['skippedConfiguration']>0&&$p2['summary']['skippedAuthRequired']>0&&$p2['summary']['skippedUnsupported']>0,'Plateformes non configurées, non autorisées et incompatibles exclues');
p50_metrics_assert_safe(['skippedAuthRequired'=>$p2['summary']['skippedAuthRequired']],'metadata');
foreach(['authorization','Authorization','token','secret','password','cookie'] as $sensitive){
    $rejected=false;try{p50_metrics_assert_safe([$sensitive=>'fixture'],'metadata');}catch(InvalidArgumentException){$rejected=true;}
    orchestrator_must($rejected,'Champ sensible toujours refusé : '.$sensitive);
}
$authDispatch=p50_mo_dispatch($pdo,'p2','fixture-auth-exclusions');
$authRun=$pdo->query("SELECT status,metadata_json FROM p50_metric_runs WHERE collector='metrics_orchestrator_v1' AND trigger_type='dispatch_p2' ORDER BY id DESC LIMIT 1")->fetch();
$authMetadata=json_decode((string)$authRun['metadata_json'],true);
orchestrator_must($authRun['status']==='success'&&($authMetadata['skippedAuthRequired']??0)>0,'Dispatch avec exclusions faute d’autorisation réussi');

$freshAccount=p50_metrics_upsert_account($pdo,['profileId'=>'fresh','platform'=>'YouTube','platformAccountId'=>'UCfresh','canonicalUrl'=>'https://youtube.com/@Fresh','sourceType'=>'fixture','observedAt'=>gmdate('c'),'provenance'=>['fixture'=>'orchestrator']]);
p50_metrics_record_capture($pdo,['accountId'=>$freshAccount['id'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>gmdate('c'),'followers'=>10,'confidence'=>99,'provenance'=>['fixture'=>'orchestrator']]);
$freshSelection=p50_mo_select($pdo,'p2');orchestrator_must(!in_array('fresh',array_column($freshSelection['candidates'],'profileId'),true)&&$freshSelection['summary']['skippedFresh']>0,'Fraîcheur P2');

$now=gmdate('c');$first=p50_mo_dispatch($pdo,'p0','fixture-dispatch',['now'=>$now]);$same=p50_mo_dispatch($pdo,'p0','fixture-replay',['now'=>$now]);
orchestrator_must($first['enqueued']>0&&$same['enqueued']===0&&$same['summary']['duplicateJobs']>0,'Même bucket idempotent');
$later=p50_mo_dispatch($pdo,'p0','fixture-next',['now'=>gmdate('c',time()+901)]);
orchestrator_must($later['enqueued']>0,'Nouveau bucket crée des tâches');

$pdo->exec("DELETE FROM p50_metric_jobs");
foreach([100,50,10] as $priority)p50_metrics_enqueue_job($pdo,['idempotencyKey'=>'priority-'.$priority,'collector'=>'youtube_v1','platform'=>'YouTube','scopeType'=>'profile','scopeId'=>'live','priority'=>$priority,'payload'=>['profileId'=>'live','platform'=>'YouTube','cadence'=>$priority===10?'p0':($priority===50?'p1':'p2'),'contentLimit'=>3,'observedAt'=>gmdate('Y-m-d H:i:s')]]);
$claimed=p50_mo_claim($pdo);$claimedSecond=p50_mo_claim($pdo);
orchestrator_must((int)$claimed['priority']===10&&$claimed['job_uuid']!==$claimedSecond['job_uuid'],'Priorité et claim exclusif');
p50_mo_finalize($pdo,$claimed,'completed');p50_mo_finalize($pdo,$claimedSecond,'completed');

$stale=p50_metrics_enqueue_job($pdo,['idempotencyKey'=>'stale-job','collector'=>'youtube_v1','platform'=>'YouTube','scopeType'=>'profile','scopeId'=>'live','priority'=>10,'payload'=>['fixture'=>true]]);
$pdo->prepare("UPDATE p50_metric_jobs SET status='running',attempts=1,locked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE),lock_token='stale' WHERE id=?")->execute([$stale['id']]);
$recovered=p50_metrics_recover_stale_jobs($pdo,10);orchestrator_must($recovered['retried']===1,'Tâche bloquée récupérée');
$pdo->prepare("UPDATE p50_metric_jobs SET status='running',attempts=max_attempts,locked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE),lock_token='stale' WHERE id=?")->execute([$stale['id']]);
$terminal=p50_metrics_recover_stale_jobs($pdo,10);orchestrator_must($terminal['failed']===1,'Tâche bloquée terminale');
orchestrator_must(p50_mo_retry_delay(1)===5&&p50_mo_retry_delay(2)===30&&p50_mo_retry_delay(3)===120&&p50_mo_retry_delay(1,true)===60,'Délais de retry');

$pdo->exec("DELETE FROM p50_metric_jobs");$workDispatch=p50_mo_dispatch($pdo,'p0','fixture-worker',['now'=>gmdate('c',time()+1801)]);
$fetch=function(string $url,array $headers=[],string $method='GET',?array $json=null): array {
    if(str_contains($url,'youtube/v3/channels'))$body=['items'=>[['id'=>'UCscheduled','snippet'=>['title'=>'Scheduled'],'statistics'=>['subscriberCount'=>'10','viewCount'=>'100','videoCount'=>'1'],'contentDetails'=>['relatedPlaylists'=>['uploads'=>'UUscheduled']]]]];
    elseif(str_contains($url,'playlistItems'))$body=['items'=>[['contentDetails'=>['videoId'=>'scheduled-video']]]];
    elseif(str_contains($url,'youtube/v3/videos'))$body=['items'=>[['id'=>'scheduled-video','snippet'=>['title'=>'Scheduled Video','publishedAt'=>gmdate('c')],'statistics'=>['viewCount'=>'20','likeCount'=>'0','commentCount'=>'1'],'status'=>['privacyStatus'=>'public']]]];
    else return ['status'=>404,'body'=>'{}','url'=>$url,'error'=>'fixture_missing'];
    return ['status'=>200,'body'=>json_encode($body),'url'=>$url,'error'=>''];
};
$worked=p50_metrics_process_next_job($pdo,['fetch'=>$fetch]);orchestrator_must($worked['processed']===1&&$worked['status']==='completed','Worker collecte une tâche');
$job=$pdo->prepare("SELECT job_uuid,payload_json FROM p50_metric_jobs WHERE job_uuid=?");$job->execute([$worked['jobUuid']]);$jobRow=$job->fetch();$payload=json_decode($jobRow['payload_json'],true);
$run=$pdo->prepare("SELECT job_uuid,trigger_type FROM p50_metric_runs WHERE job_uuid=? ORDER BY id DESC LIMIT 1");$run->execute([$worked['jobUuid']]);$runRow=$run->fetch();
orchestrator_must($runRow['job_uuid']===$worked['jobUuid']&&$runRow['trigger_type']==='scheduled_p0','Run lié au job et trigger planifié');
$captureObserved=(string)p50_metrics_value($pdo,"SELECT observed_at FROM p50_metric_captures WHERE run_uuid=(SELECT run_uuid FROM p50_metric_runs WHERE job_uuid=? ORDER BY id DESC LIMIT 1) LIMIT 1",[$worked['jobUuid']]);
orchestrator_must($captureObserved===$payload['observedAt'],'observedAt de la tâche conservé');

$pdo->exec("DELETE FROM p50_metric_jobs WHERE status IN ('pending','running','retry_wait')");
$skip=p50_metrics_enqueue_job($pdo,['idempotencyKey'=>'skip-x','collector'=>'x_v1','platform'=>'X','scopeType'=>'profile','scopeId'=>'live','priority'=>10,'payload'=>['profileId'=>'live','platform'=>'X','cadence'=>'p0','contentLimit'=>3,'observedAt'=>gmdate('Y-m-d H:i:s')]]);
$skipped=p50_metrics_process_next_job($pdo,['fetch'=>$fetch]);orchestrator_must($skipped['status']==='skipped','Configuration absente ignorée pour le bucket');

$body='{"action":"dispatch","cadence":"p0","dispatchId":"fixture"}';$timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp."\n".$body,$cronSecret);
orchestrator_must(p50_mo_verify_cron_signature($cronSecret,$timestamp,$body,$signature),'Signature HMAC valide');
orchestrator_must(!p50_mo_verify_cron_signature($cronSecret,$timestamp,$body,str_repeat('0',64)),'Signature HMAC invalide');
orchestrator_must(!p50_mo_verify_cron_signature($cronSecret,(string)(time()-301),$body,$signature),'Timestamp ancien refusé');
orchestrator_must(!p50_mo_verify_cron_signature('short',$timestamp,$body,$signature),'Secret court refusé');

$status=p50_mo_status($pdo);orchestrator_must(isset($status['expectedCadences']['p0'],$status['queue']['completed24h']),'Diagnostic orchestrateur');
orchestrator_must((string)p50_metrics_value($pdo,"SELECT state_json FROM app_state WHERE id=1")==='{"pr5Sentinel":true}'&&(int)p50_metrics_value($pdo,"SELECT version FROM app_state WHERE id=1")===99,'app_state inchangé');
orchestrator_must((string)p50_metrics_value($pdo,"SELECT score FROM p50_profile_registry WHERE profile_id='live'")==='50.00','Scores inchangés');
orchestrator_must((int)p50_metrics_value($pdo,"SELECT rank_position FROM p50_ranking_snapshots WHERE profile_id='live' ORDER BY id DESC LIMIT 1")===1,'Rangs inchangés');
$stored=(string)p50_metrics_value($pdo,"SELECT CONCAT(COALESCE(GROUP_CONCAT(payload_json SEPARATOR ' '),''),' ',COALESCE((SELECT GROUP_CONCAT(metadata_json SEPARATOR ' ') FROM p50_metric_runs),'')) FROM p50_metric_jobs");
orchestrator_must(!str_contains($stored,$cronSecret)&&!str_contains($stored,$youtubeKey),'Aucun secret stocké');

$stateBefore=(string)p50_metrics_value($pdo,"SELECT state_json FROM app_state WHERE id=1");
$scoreBefore=(string)p50_metrics_value($pdo,"SELECT score FROM p50_profile_registry WHERE profile_id='live'");
$rankBefore=(int)p50_metrics_value($pdo,"SELECT rank_position FROM p50_ranking_snapshots WHERE profile_id='live' ORDER BY id DESC LIMIT 1");
$pdo->exec("DELETE FROM p50_metric_jobs");
$failedFixture=p50_metrics_enqueue_job($pdo,['idempotencyKey'=>'diagnostic-failed','collector'=>'youtube_v1','platform'=>'YouTube','scopeType'=>'profile','scopeId'=>'live','priority'=>10,'maxAttempts'=>4,'payload'=>['profileId'=>'live','platform'=>'YouTube','cadence'=>'p0','fixtureMarker'=>'must-not-be-returned']]);
$pendingFixture=p50_metrics_enqueue_job($pdo,['idempotencyKey'=>'diagnostic-pending','collector'=>'x_v1','platform'=>'X','scopeType'=>'profile','scopeId'=>'near','priority'=>50,'payload'=>['profileId'=>'near','platform'=>'X','cadence'=>'p1']]);
$sensitiveError='Échec https://fixture.invalid/private contact fixture@example.test Bearer fixture-bearer token=fixture';
$pdo->prepare("UPDATE p50_metric_jobs SET status='failed',attempts=max_attempts,lock_token='fixture-lock',last_error=? WHERE id=?")->execute([$sensitiveError,$failedFixture['id']]);
$failures=p50_obs_recent_metric_failures($pdo,20);
orchestrator_must(count($failures)===1,'Le diagnostic retourne uniquement les tâches failed');
$failure=$failures[0];
orchestrator_must(array_keys($failure)===['updatedAt','collector','platform','profileId','cadence','attempts','maxAttempts','message'],'Contrat public exact du diagnostic failed');
orchestrator_must($failure['platform']==='YouTube'&&$failure['profileId']==='live'&&$failure['cadence']==='p0','Plateforme, profil et cadence du job failed');
orchestrator_must($failure['attempts']===4&&$failure['maxAttempts']===4,'Tentatives du job failed');
orchestrator_must(str_contains($failure['message'],'[url]')&&str_contains($failure['message'],'[email]')&&str_contains($failure['message'],'Bearer [redacted]')&&str_contains($failure['message'],'token=[redacted]'),'Erreur du job failed nettoyée');
foreach(['jobUuid','scopeType','scopeId','priority','createdAt','failedAt','payload_json','lock_token','idempotency_key'] as $forbidden)orchestrator_must(!array_key_exists($forbidden,$failure),'Champ interne absent du diagnostic : '.$forbidden);
orchestrator_must(!str_contains(json_encode($failure,JSON_UNESCAPED_SLASHES),'must-not-be-returned'),'Payload brut absent du diagnostic');
$pdo->prepare("UPDATE p50_metric_jobs SET last_error='' WHERE id=?")->execute([$failedFixture['id']]);
$emptyFailure=p50_obs_recent_metric_failures($pdo,20)[0];
orchestrator_must($emptyFailure['message']==='Échec sans détail','Erreur vide explicitement décrite');
orchestrator_must((string)p50_metrics_value($pdo,"SELECT state_json FROM app_state WHERE id=1")===$stateBefore,'Diagnostic sans modification de app_state');
orchestrator_must((string)p50_metrics_value($pdo,"SELECT score FROM p50_profile_registry WHERE profile_id='live'")===$scoreBefore,'Diagnostic sans modification des scores');
orchestrator_must((int)p50_metrics_value($pdo,"SELECT rank_position FROM p50_ranking_snapshots WHERE profile_id='live' ORDER BY id DESC LIMIT 1")===$rankBefore,'Diagnostic sans modification des rangs');

echo json_encode(['ok'=>true,'p0'=>$p0['summary'],'p1'=>$p1['summary'],'p2'=>$p2['summary'],'first'=>$first,'sameBucket'=>$same,'newBucket'=>$later,'recovery'=>$recovered,'worker'=>$worked,'skipped'=>$skipped,'status'=>$status],JSON_UNESCAPED_SLASHES).PHP_EOL;
