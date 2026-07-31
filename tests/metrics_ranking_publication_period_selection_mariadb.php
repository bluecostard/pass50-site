<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-ranking-publication-period-core.php';

$dsn=getenv('P50_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=pass50_test;charset=utf8mb4';
$user=getenv('P50_TEST_DB_USER')?:'root';
$password=getenv('P50_TEST_DB_PASSWORD')?:'root';
$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '+00:00'");

foreach(['p50_metric_ranking_current','p50_metric_ranking_runs','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE app_state(id VARCHAR(32) PRIMARY KEY,data LONGTEXT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190) NOT NULL,handle VARCHAR(190) NOT NULL DEFAULT '',region VARCHAR(32) NOT NULL DEFAULT 'CI')");
$pdo->exec("CREATE TABLE p50_metric_ranking_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_uuid CHAR(36) NOT NULL,algorithm_version VARCHAR(24) NOT NULL,trigger_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,periods_json LONGTEXT NOT NULL,finished_at DATETIME NULL)");
$pdo->exec("CREATE TABLE p50_metric_ranking_current(algorithm_version VARCHAR(24) NOT NULL,period_key VARCHAR(8) NOT NULL,profile_id VARCHAR(100) NOT NULL,run_uuid CHAR(36) NOT NULL,rank_position INT NULL,score DECIMAL(7,3) NULL,confidence DECIMAL(7,3) NOT NULL,coverage DECIMAL(7,3) NOT NULL,classable TINYINT(1) NOT NULL,exclusion_reasons_json LONGTEXT NOT NULL,PRIMARY KEY(algorithm_version,period_key,profile_id))");

$state=['stateRevision'=>11,'profiles'=>[
    ['id'=>'A','name'=>'Alpha','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['2H'=>90,'24H'=>91]],
    ['id'=>'B','name'=>'Bravo','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['2H'=>80,'24H'=>81]],
    ['id'=>'C','name'=>'Charlie','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['2H'=>70,'24H'=>71]],
]];
$encoded=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$pdo->prepare("INSERT INTO app_state(id,data) VALUES('public',?)")->execute([$encoded]);
$stmt=$pdo->prepare("INSERT INTO p50_profile_registry(profile_id,public_name,handle,region) VALUES(?,?,?,?)");
foreach([['A','Alpha','@alpha','CI'],['B','Bravo','@bravo','CI'],['C','Charlie','@charlie','CI']] as $row)$stmt->execute($row);
$runUuid='22222222-2222-4222-8222-222222222222';
$pdo->prepare("INSERT INTO p50_metric_ranking_runs(run_uuid,algorithm_version,trigger_type,status,periods_json,finished_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())")
    ->execute([$runUuid,P50_MR_ALGORITHM_VERSION,'cron_2h','success','[\"2H\",\"24H\",\"48H\",\"7J\",\"15J\"]']);
$stmt=$pdo->prepare("INSERT INTO p50_metric_ranking_current(algorithm_version,period_key,profile_id,run_uuid,rank_position,score,confidence,coverage,classable,exclusion_reasons_json) VALUES(?,?,?,?,?,?,?,?,?,?)");
foreach([
    [P50_MR_ALGORITHM_VERSION,'2H','A',$runUuid,null,35,42,38,0,'[\"coverage_below_45\"]'],
    [P50_MR_ALGORITHM_VERSION,'2H','B',$runUuid,null,30,40,35,0,'[\"coverage_below_45\"]'],
    [P50_MR_ALGORITHM_VERSION,'2H','C',$runUuid,null,25,39,34,0,'[\"coverage_below_45\"]'],
    [P50_MR_ALGORITHM_VERSION,'24H','B',$runUuid,1,88,85,80,1,'[]'],
    [P50_MR_ALGORITHM_VERSION,'24H','A',$runUuid,2,84,82,78,1,'[]'],
    [P50_MR_ALGORITHM_VERSION,'24H','C',$runUuid,3,79,80,75,1,'[]'],
] as $row)$stmt->execute($row);

$assert=static function(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);};
$before=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();
$result=p50_mrpa_simulate($pdo,'2H',200,new DateTimeImmutable('now',new DateTimeZone('UTC')));
$after=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();

$assert($before===$after,'Le repli de période a modifié app_state.');
$assert($result['requestedPeriod']==='2H','La période demandée doit rester traçable.');
$assert($result['selectedPeriod']==='24H','La simulation doit choisir 24H lorsque 2H est vide.');
$assert($result['periodSelection']['reason']==='requested_period_empty_fallback','Le motif de repli est incorrect.');
$assert($result['periodSelection']['fallbackUsed']===true,'Le repli doit être signalé.');
$assert($result['periodAvailability']['2H']['candidateRows']===0,'2H doit rester diagnostiquée vide.');
$assert($result['periodAvailability']['24H']['candidateRows']===3,'24H doit exposer trois candidats.');
$assert($result['summary']['candidateCount']===3,'Trois candidats 24H sont attendus.');
$assert($result['publication']['publicationEnabled']===false,'La publication doit rester désactivée.');
$assert($result['scope']['publicStateWrites']===0,'Aucune écriture publique ne doit être déclarée.');

$pdo->exec("UPDATE p50_metric_ranking_current SET classable=1,rank_position=CASE profile_id WHEN 'A' THEN 1 WHEN 'B' THEN 2 ELSE 3 END,confidence=80,coverage=75,exclusion_reasons_json='[]' WHERE period_key='2H'");
$result2=p50_mrpa_simulate($pdo,'2H',200,new DateTimeImmutable('now',new DateTimeZone('UTC')));
$after2=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();
$assert($after2===$before,'Le retour à 2H a modifié app_state.');
$assert($result2['selectedPeriod']==='2H','2H doit être conservée dès qu’elle redevient classable.');
$assert($result2['periodSelection']['reason']==='requested_period_classable','Le motif de sélection 2H est incorrect.');
$assert($result2['periodSelection']['fallbackUsed']===false,'Aucun repli ne doit être signalé pour 2H classable.');
$assert($result2['summary']['candidateCount']===3,'Trois candidats 2H sont attendus.');

echo "Metrics publication period selection MariaDB: OK\n";
