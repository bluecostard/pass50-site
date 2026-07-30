<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-queue-core.php';

$dsn=getenv('P50_TEST_DSN');if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function queue_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

foreach(['p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE app_state(id INT PRIMARY KEY,state_json LONGTEXT,version INT)");
$pdo->exec("INSERT INTO app_state VALUES(1,'{\"queueSentinel\":true}',7)");
p50_metrics_ensure_schema($pdo);

$p0=p50_metrics_enqueue_job($pdo,[
    'idempotencyKey'=>'queue-p0','collector'=>'youtube_v1','platform'=>'YouTube',
    'scopeType'=>'profile','scopeId'=>'p0-profile','priority'=>10,
    'payload'=>['profileId'=>'p0-profile','platform'=>'YouTube','cadence'=>'p0','observedAt'=>gmdate('Y-m-d H:i:s')],
]);
$p1=p50_metrics_enqueue_job($pdo,[
    'idempotencyKey'=>'queue-p1','collector'=>'youtube_v1','platform'=>'YouTube',
    'scopeType'=>'profile','scopeId'=>'p1-profile','priority'=>50,'maxAttempts'=>3,
    'payload'=>['profileId'=>'p1-profile','platform'=>'YouTube','cadence'=>'p1','observedAt'=>gmdate('Y-m-d H:i:s')],
]);
$pdo->prepare("UPDATE p50_metric_jobs SET status='retry_wait',attempts=1,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 MINUTE),last_error='timeout https://private.example.test token=fixture' WHERE id=?")->execute([$p1['id']]);

$waiting=p50_moq_snapshot($pdo);
queue_must($waiting['remaining']===2,'Deux tâches actives globales attendues.');
queue_must($waiting['pending']===1&&$waiting['retryWait']===1,'Une tâche pending et une retry_wait attendues.');
queue_must($waiting['p1Remaining']===1,'Une seule tâche P1 active attendue.');
queue_must($waiting['p1Pending']===0&&$waiting['p1Running']===0&&$waiting['p1RetryWait']===1,'La tâche P1 doit être en retry_wait.');
queue_must($waiting['p1WaitSeconds']>=60&&$waiting['p1WaitSeconds']<=125,'Le délai P1 doit refléter le prochain réessai.');
queue_must(is_array($waiting['p1NextJob']),'La prochaine tâche P1 doit être décrite.');
queue_must(array_keys($waiting['p1NextJob'])===['platform','profileId','status','attempts','maxAttempts','nextAttemptAt'],'Contrat minimal exact de la prochaine tâche.');
queue_must($waiting['p1NextJob']['profileId']==='p1-profile'&&$waiting['p1NextJob']['attempts']===1,'Profil et tentative P1 attendus.');
queue_must(!str_contains(json_encode($waiting['p1NextJob'],JSON_UNESCAPED_SLASHES),'private.example.test'),'Aucun message d’erreur ne doit être exposé.');
queue_must(!str_contains(json_encode($waiting['p1NextJob'],JSON_UNESCAPED_SLASHES),'token=fixture'),'Aucun secret d’erreur ne doit être exposé.');

$pdo->prepare("UPDATE p50_metric_jobs SET status='completed',next_attempt_at=NULL,last_error=NULL WHERE id=?")->execute([$p1['id']]);
$ready=p50_moq_snapshot($pdo);
queue_must($ready['p1Remaining']===0,'La file P1 doit être vide après finalisation.');
queue_must($ready['remaining']===1&&$ready['pending']===1,'La tâche P0 globale doit rester active.');
queue_must($ready['p1NextJob']===null&&$ready['p1WaitSeconds']===0,'Aucun réessai P1 ne doit rester.');

queue_must((string)p50_metrics_value($pdo,"SELECT state_json FROM app_state WHERE id=1")==='{"queueSentinel":true}','La lecture de file a modifié app_state.');
queue_must((int)p50_metrics_value($pdo,"SELECT version FROM app_state WHERE id=1")===7,'La version publique a changé.');

echo json_encode(['ok'=>true,'waiting'=>$waiting,'ready'=>$ready],JSON_UNESCAPED_SLASHES).PHP_EOL;
