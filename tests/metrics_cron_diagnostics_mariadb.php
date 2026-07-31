<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-queue-core.php';
require dirname(__DIR__).'/api/metrics-cron-diagnostics-core.php';

$dsn=getenv('P50_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=pass50_test;charset=utf8mb4';
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '+00:00'");
foreach(['p50_metric_jobs','p50_metric_runs','p50_metric_captures','p50_metric_contents','p50_metric_accounts','p50_metric_schema_migrations'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
p50_metrics_ensure_schema($pdo);
$job=p50_metrics_enqueue_job($pdo,[
    'idempotencyKey'=>hash('sha256','diag-fixture'),'collector'=>'youtube_v1','platform'=>'YouTube',
    'scopeType'=>'profile','scopeId'=>'profile-alpha','priority'=>50,'maxAttempts'=>3,
    'payload'=>['profileId'=>'profile-alpha','platform'=>'YouTube','cadence'=>'p1','observedAt'=>'2026-07-31 08:00:00'],
]);
$pdo->prepare("UPDATE p50_metric_jobs SET status='completed',attempts=1,last_error=? WHERE job_uuid=?")
    ->execute(['YouTube quota_exceeded token=SECRET_VALUE',$job['jobUuid']]);
$work=[
    'processed'=>1,'jobUuid'=>$job['jobUuid'],'status'=>'completed','result'=>[
        'platform'=>'YouTube','profileId'=>'profile-alpha','status'=>'success','accountFound'=>true,
        'contentsFound'=>5,'capturesRecorded'=>0,'duplicatesSkipped'=>6,'quarantined'=>1,'unavailableMetrics'=>2,
        'requestsAttempted'=>3,'requestsSucceeded'=>2,'rateLimited'=>false,
        'errors'=>['HTTP 403 forbidden SECRET_VALUE'],
    ],
];
$diag=p50_mcd_work($pdo,$work);
$must=static function(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);};
$must(is_array($diag),'Diagnostic attendu.');
$must($diag['version']==='WORK-DIAG-V1.0','Version de diagnostic incorrecte.');
$must($diag['profileId']==='profile-alpha'&&$diag['platform']==='YouTube','Identité de tâche incorrecte.');
$must($diag['capturesRecorded']===0&&$diag['duplicatesSkipped']===6&&$diag['quarantined']===1,'Compteurs de capture incorrects.');
$must(in_array('quota_exceeded',$diag['errorCodes'],true)&&in_array('forbidden',$diag['errorCodes'],true),'Codes d’erreur assainis attendus.');
$encoded=json_encode($diag,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$must(!str_contains($encoded,'SECRET_VALUE'),'Le message brut ou secret ne doit pas sortir.');
$must(!array_key_exists('errors',$diag)&&!array_key_exists('lastError',$diag),'Aucun texte d’erreur brut ne doit sortir.');
$must(p50_mcd_work($pdo,['processed'=>0])===null,'Aucun diagnostic ne doit être créé sans tâche traitée.');
echo "Metrics cron diagnostics MariaDB: OK\n";
