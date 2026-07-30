<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-ranking-readiness-core.php';

$dsn=getenv('P50_TEST_DSN');
if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
function readiness_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

foreach(['p50_metric_ranking_runs','p50_metric_captures','p50_metric_jobs','p50_metric_runs'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$now=new DateTimeImmutable('2026-07-30 12:00:00',new DateTimeZone('UTC'));

$missing=p50_mrr_readiness($pdo,$now);
readiness_must($missing['reason']==='schema_missing'&&!$missing['ready'],'Schéma manquant bloqué');

$pdo->exec("CREATE TABLE p50_metric_runs(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_uuid CHAR(36),collector VARCHAR(64),trigger_type VARCHAR(32),status VARCHAR(24),started_at DATETIME,finished_at DATETIME NULL
)");
$pdo->exec("CREATE TABLE p50_metric_jobs(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,priority SMALLINT UNSIGNED,status VARCHAR(24),updated_at DATETIME
)");
$pdo->exec("CREATE TABLE p50_metric_captures(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quality_status VARCHAR(24),captured_at DATETIME
)");
$pdo->exec("CREATE TABLE p50_metric_ranking_runs(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,algorithm_version VARCHAR(24),status VARCHAR(24),finished_at DATETIME NULL
)");

$noP1=p50_mrr_readiness($pdo,$now);
readiness_must($noP1['reason']==='p1_not_observed','P1 absent détecté');

$pdo->exec("INSERT INTO p50_metric_runs(run_uuid,collector,trigger_type,status,started_at,finished_at) VALUES(
    '11111111-1111-4111-8111-111111111111','metrics_orchestrator_v1','dispatch_p1','success','2026-07-30 11:35:00','2026-07-30 11:45:00'
)");
$pdo->exec("INSERT INTO p50_metric_jobs(priority,status,updated_at) VALUES(50,'pending','2026-07-30 11:46:00')");
$pending=p50_mrr_readiness($pdo,$now);
readiness_must($pending['reason']==='collection_pending'&&$pending['state']==='waiting'&&$pending['p1']['activeJobs']===1,'File P1 active détectée');

$pdo->exec("DELETE FROM p50_metric_jobs");
$noCapture=p50_mrr_readiness($pdo,$now);
readiness_must($noCapture['reason']==='no_usable_captures','Absence de capture détectée');

$pdo->exec("INSERT INTO p50_metric_captures(quality_status,captured_at) VALUES('usable','2026-07-30 11:50:00')");
$ready=p50_mrr_readiness($pdo,$now);
readiness_must($ready['ready']===true&&$ready['reason']==='ready','Première donnée prête');

$pdo->exec("INSERT INTO p50_metric_ranking_runs(algorithm_version,status,finished_at) VALUES('MR-V1.0','success','2026-07-30 11:55:00')");
$unchanged=p50_mrr_readiness($pdo,$now);
readiness_must($unchanged['reason']==='no_new_captures'&&$unchanged['state']==='idle','Absence de nouvelle donnée détectée');

$pdo->exec("INSERT INTO p50_metric_captures(quality_status,captured_at) VALUES('usable','2026-07-30 11:58:00')");
$pdo->exec("INSERT INTO p50_metric_jobs(priority,status,updated_at) VALUES(50,'failed','2026-07-30 11:47:00')");
$degraded=p50_mrr_readiness($pdo,$now);
readiness_must($degraded['ready']===true&&$degraded['state']==='ready_degraded'&&$degraded['reason']==='ready_with_partial_failures','Erreurs partielles non bloquantes');

$pdo->exec("UPDATE p50_metric_runs SET started_at='2026-07-30 07:30:00',finished_at='2026-07-30 08:00:00'");
$stale=p50_mrr_readiness($pdo,$now);
readiness_must($stale['reason']==='p1_stale'&&!$stale['ready'],'P1 ancien bloqué');

fwrite(STDOUT,json_encode(['ok'=>true,'states'=>[
    $missing['reason'],$noP1['reason'],$pending['reason'],$noCapture['reason'],$ready['reason'],$unchanged['reason'],$degraded['reason'],$stale['reason'],
]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
