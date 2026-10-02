<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-public-baseline-core.php';

$dsn=getenv('P50_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=pass50_test;charset=utf8mb4';
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone = '+00:00'");
function baseline_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

$config=['data_engine'=>['confidence_threshold'=>90],'metrics'=>[
  'PASS50_YOUTUBE_API_KEY'=>hash('sha256','baseline-youtube-fixture'),'orchestrator_enabled'=>true,
  'cron_secret'=>hash('sha256','baseline-cron-fixture'),'p1_max_profiles'=>200,'p1_max_rank'=>200,
  'p1_min_freshness_minutes'=>90,'p0_min_freshness_minutes'=>12,'p2_min_freshness_minutes'=>600,
]];
foreach(['p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_ranking_snapshots','p50_live_streams','p50_social_links','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE app_state(id VARCHAR(32) PRIMARY KEY,data LONGTEXT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190),alive TINYINT NOT NULL,score DECIMAL(6,2))");
$pdo->exec("CREATE TABLE p50_social_links(profile_id VARCHAR(100),platform VARCHAR(32),normalized_url TEXT,confidence INT,status VARCHAR(24),PRIMARY KEY(profile_id,platform))");
$pdo->exec("CREATE TABLE p50_ranking_snapshots(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),period_key VARCHAR(16),rank_position INT,trend_score DECIMAL(6,2),rank_delta INT,badges TEXT,data_confidence INT,captured_at DATETIME,INDEX(profile_id,captured_at))");
$pdo->exec("CREATE TABLE p50_live_streams(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),status VARCHAR(24),last_seen_at DATETIME)");

$state=['stateRevision'=>17,'profiles'=>[
  ['id'=>'A','name'=>'Alpha','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['24H'=>90]],
  ['id'=>'B','name'=>'Bravo','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['24H'=>80]],
  ['id'=>'C','name'=>'Charlie','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>['24H'=>70]],
  ['id'=>'D','name'=>'Dead','alive'=>false,'eligible'=>true,'classable'=>true,'scores'=>['24H'=>60]],
  ['id'=>'E','name'=>'Unranked','alive'=>true,'eligible'=>true,'classable'=>true,'scores'=>[]],
]];
$stateJson=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$pdo->prepare("INSERT INTO app_state(id,data) VALUES('public',?)")->execute([$stateJson]);
foreach([['A','Alpha',1,90],['B','Bravo',1,80],['C','Charlie',1,70],['D','Dead',0,60],['E','Unranked',1,50]] as $row)$pdo->prepare("INSERT INTO p50_profile_registry VALUES(?,?,?,?)")->execute($row);
foreach(['A','B','C','D','E'] as $id)$pdo->prepare("INSERT INTO p50_social_links VALUES(?,'YouTube',?,98,'verified')")->execute([$id,'https://youtube.com/@'.$id]);
$pdo->prepare("INSERT INTO p50_ranking_snapshots(profile_id,period_key,rank_position,trend_score,rank_delta,badges,data_confidence,captured_at) VALUES('A','current',1,90,0,'[]',99,UTC_TIMESTAMP())")->execute();
p50_metrics_ensure_schema($pdo);

$before=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();
$ids=p50_mopb_public_profile_ids($pdo,100);baseline_must($ids===['A','B','C'],'La couverture doit reprendre les trois profils réellement classés.');
$now='2026-07-31T08:00:00Z';$first=p50_mopb_dispatch($pdo,'baseline-fixture-1',$now);
baseline_must($first['version']==='PUBLIC-BASELINE-P1-V1.3','Version de couverture attendue.');
baseline_must($first['summary']['publicProfiles']===3,'Trois profils publics attendus.');
baseline_must($first['summary']['eligibleLinksByPlatform']['YouTube']===3,'Trois liens YouTube vérifiés attendus.');
baseline_must($first['summary']['selectedByPlatform']['YouTube']===3,'Trois sources YouTube doivent être retenues.');
baseline_must($first['summary']['jobsCreated']===3&&$first['summary']['jobsCreatedByPlatform']['YouTube']===3,'Une tâche YouTube attendue pour chaque profil public.');
baseline_must($first['summary']['publicProfilesWithoutVerifiedSources']===[],'Aucun profil public ne doit manquer de source vérifiée.');
baseline_must($first['publicStateWrites']===0,'Aucune écriture publique ne doit être annoncée.');
$second=p50_mopb_dispatch($pdo,'baseline-fixture-2',$now);
baseline_must($second['summary']['jobsCreated']===0&&$second['summary']['duplicateJobs']===3,'Le même bucket doit être idempotent.');
baseline_must($second['summary']['duplicateJobsByPlatform']['YouTube']===3,'Les doublons doivent être ventilés par plateforme.');

$regular=p50_mo_dispatch($pdo,'p1','regular-p1-fixture',['now'=>$now]);
$baselineJobs=(int)$pdo->query('SELECT COUNT(*) FROM p50_metric_jobs WHERE priority='.(int)P50_METRICS_PUBLIC_BASELINE_PRIORITY)->fetchColumn();
baseline_must($baselineJobs===3,'La couverture publique reste sur une file distincte (priorité 20).');
$jobCount=(int)$pdo->query("SELECT COUNT(*) FROM p50_metric_jobs WHERE priority=50")->fetchColumn();
baseline_must($jobCount>=1,'Le P1 normal conserve sa file priorité 50.');
baseline_must((int)($regular['summary']['jobsCreated']??0)+(int)($regular['summary']['skippedFresh']??0)>=1,'Le P1 normal crée des jobs ou saute les profils déjà couverts en priorité plus haute.');
$after=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();baseline_must($before===$after,'La couverture P1 a modifié app_state.');
$payloads=(string)$pdo->query("SELECT GROUP_CONCAT(payload_json SEPARATOR ' ') FROM p50_metric_jobs")->fetchColumn();
baseline_must(str_contains($payloads,'public_baseline'),'Le motif de couverture doit être traçable.');
baseline_must(!str_contains($payloads,(string)$config['metrics']['cron_secret']),'Le secret cron ne doit jamais être stocké.');
echo "Metrics public baseline P1 MariaDB: OK\n";
