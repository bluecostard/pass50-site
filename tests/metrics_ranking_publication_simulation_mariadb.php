<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-ranking-publication-core.php';

$dsn=getenv('P50_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=pass50_test;charset=utf8mb4';
$user=getenv('P50_TEST_DB_USER')?:'root';
$password=getenv('P50_TEST_DB_PASSWORD')?:'root';
$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

foreach(['p50_metric_ranking_current','p50_metric_ranking_runs','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE app_state(id VARCHAR(32) PRIMARY KEY,data LONGTEXT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190) NOT NULL,handle VARCHAR(190) NOT NULL DEFAULT '',region VARCHAR(32) NOT NULL DEFAULT 'CI')");
$pdo->exec("CREATE TABLE p50_metric_ranking_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,run_uuid CHAR(36) NOT NULL,algorithm_version VARCHAR(24) NOT NULL,trigger_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,periods_json LONGTEXT NOT NULL,finished_at DATETIME NULL)");
$pdo->exec("CREATE TABLE p50_metric_ranking_current(algorithm_version VARCHAR(24) NOT NULL,period_key VARCHAR(8) NOT NULL,profile_id VARCHAR(100) NOT NULL,rank_position INT NULL,score DECIMAL(7,3) NULL,confidence DECIMAL(7,3) NOT NULL,coverage DECIMAL(7,3) NOT NULL,classable TINYINT(1) NOT NULL,exclusion_reasons_json LONGTEXT NOT NULL,PRIMARY KEY(algorithm_version,period_key,profile_id))");

$state=['stateRevision'=>7,'profiles'=>[
    ['id'=>'A','name'=>'Alpha','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['2H'=>90]],
    ['id'=>'B','name'=>'Bravo','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['2H'=>80]],
    ['id'=>'C','name'=>'Charlie','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['2H'=>70]],
    ['id'=>'D','name'=>'Delta','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>[]],
]];
$encoded=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$stmt=$pdo->prepare("INSERT INTO app_state(id,data) VALUES('public',?)");$stmt->execute([$encoded]);
$stmt=$pdo->prepare("INSERT INTO p50_profile_registry(profile_id,public_name,handle,region) VALUES(?,?,?,?)");
foreach([['A','Alpha','@alpha','CI'],['B','Bravo','@bravo','CI'],['C','Charlie','@charlie','CI'],['D','Delta','@delta','CI']] as $row)$stmt->execute($row);
$pdo->prepare("INSERT INTO p50_metric_ranking_runs(run_uuid,algorithm_version,trigger_type,status,periods_json,finished_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())")
    ->execute(['11111111-1111-4111-8111-111111111111',P50_MR_ALGORITHM_VERSION,'cron_2h','success','[\"2H\"]']);
$stmt=$pdo->prepare("INSERT INTO p50_metric_ranking_current(algorithm_version,period_key,profile_id,rank_position,score,confidence,coverage,classable,exclusion_reasons_json) VALUES(?,?,?,?,?,?,?,?,?)");
foreach([
    [P50_MR_ALGORITHM_VERSION,'2H','B',1,88,85,80,1,'[]'],
    [P50_MR_ALGORITHM_VERSION,'2H','D',2,85,82,78,1,'[]'],
    [P50_MR_ALGORITHM_VERSION,'2H','A',3,82,80,75,1,'[]'],
    [P50_MR_ALGORITHM_VERSION,'2H','C',null,65,40,35,0,'[\"coverage_below_45\"]'],
] as $row)$stmt->execute($row);

$before=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();
$result=p50_mrp_simulate($pdo,'2H',200,new DateTimeImmutable('now',new DateTimeZone('UTC')));
$after=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();

$assert=static function(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);};
$assert($before===$after,'La simulation a modifié app_state.');
$assert($result['publication']['publicationEnabled']===false,'La publication doit rester désactivée.');
$assert($result['publication']['appStateWriteAttempted']===false,'Aucune tentative d’écriture ne doit être déclarée.');
$assert($result['source']['publicStateRevision']===7,'La révision publique doit être capturée.');
$assert($result['summary']['counts']['entries']===1,'Une entrée attendue.');
$assert($result['summary']['counts']['exits']===1,'Une sortie attendue.');
$assert($result['summary']['counts']['up']===1,'Une hausse attendue.');
$assert($result['summary']['counts']['down']===1,'Une baisse attendue.');
$assert($result['summary']['candidateCount']===3,'Trois profils candidats attendus.');
$assert($result['orphanCandidateProfileIds']===[],'Aucun profil candidat orphelin attendu.');
$assert(strlen($result['source']['publicFingerprint'])===64,'Empreinte publique invalide.');
$assert(strlen($result['source']['candidateFingerprint'])===64,'Empreinte candidate invalide.');

echo "Metrics ranking publication simulation MariaDB: OK\n";
