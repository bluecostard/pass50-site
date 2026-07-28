<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/metrics-ranking-core.php';
require dirname(__DIR__).'/api/metrics-ranking-calibration-core.php';

$dsn=getenv('P50_TEST_DSN');if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function mr_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

foreach(['p50_metric_ranking_period_runs','p50_metric_ranking_snapshots','p50_metric_ranking_current','p50_metric_ranking_runs','p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190) NOT NULL,handle VARCHAR(190) NOT NULL DEFAULT '',region VARCHAR(32) NOT NULL DEFAULT 'CI',category VARCHAR(100) NOT NULL DEFAULT '',alive TINYINT NOT NULL DEFAULT 1,eligible TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE app_state(id INT PRIMARY KEY,state_json LONGTEXT NOT NULL,version INT NOT NULL)");
$pdo->exec("INSERT INTO app_state VALUES(1,'{\"rankingExperimentalSentinel\":true,\"publicScores\":{\"A\":88},\"publicRanks\":{\"A\":1}}',731)");
$appStateBefore=$pdo->query("SELECT state_json,version FROM app_state WHERE id=1")->fetch();
foreach([
  ['A','Profil A',1],['B','Profil B',1],['C','Profil C zéro mesuré',1],['D','Profil D absent',1],
  ['E','Profil E fallback',1],['F','Profil F quarantaine',1],['G','Profil G non éligible',0],['H','Profil H hors période',1],
] as [$id,$name,$eligible])$pdo->prepare("INSERT INTO p50_profile_registry VALUES(?,?,?,'CI','Fixture',1,?)")->execute([$id,$name,'@'.strtolower($id),$eligible]);

p50_metrics_ensure_schema($pdo);
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
function mr_at(DateTimeImmutable $now,int $hours): string {return $now->modify(($hours>=0?'+':'').$hours.' hours')->format('Y-m-d H:i:s');}
function mr_fixture(PDO $pdo,DateTimeImmutable $now,string $profile,int $startViews,int $endViews,int $startInteractions,int $endInteractions,bool $publishedInside=false,bool $quarantined=false): array {
    $account=p50_metrics_upsert_account($pdo,['profileId'=>$profile,'platform'=>'YouTube','platformAccountId'=>'UC'.$profile,'canonicalUrl'=>'https://youtube.com/@fixture'.$profile,'status'=>'active','confidence'=>95,'sourceType'=>'manual_owner','observedAt'=>mr_at($now,-30),'provenance'=>['fixture'=>'ranking']]);
    $published=$publishedInside?mr_at($now,-5):mr_at($now,-48);
    $content=p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'platformContentId'=>'video-'.$profile,'canonicalUrl'=>'https://youtube.com/watch?v=fixture'.$profile,'contentType'=>'video','publishedAt'=>$published,'status'=>'active','confidence'=>95,'sourceType'=>'manual_owner','observedAt'=>mr_at($now,-30),'provenance'=>['fixture'=>'ranking']]);
    p50_metrics_record_capture($pdo,['accountId'=>$account['id'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>mr_at($now,-1),'followers'=>1000,'confidence'=>95,'provenance'=>['fixture'=>'ranking']]);
    if(!$publishedInside)p50_metrics_record_capture($pdo,['accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>mr_at($now,-25),'views'=>$startViews,'likes'=>$startInteractions,'comments'=>$startInteractions,'shares'=>$startInteractions,'saves'=>$startInteractions,'confidence'=>95,'qualityStatus'=>$quarantined?'quarantined':'usable','provenance'=>['fixture'=>'ranking']]);
    p50_metrics_record_capture($pdo,['accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>mr_at($now,-1),'views'=>$endViews,'likes'=>$endInteractions,'comments'=>$endInteractions,'shares'=>$endInteractions,'saves'=>$endInteractions,'confidence'=>95,'qualityStatus'=>$quarantined?'quarantined':'usable','provenance'=>['fixture'=>'ranking']]);
    return ['accountId'=>$account['id'],'contentId'=>$content['id']];
}

$a=mr_fixture($pdo,$now,'A',100,1100,10,110);
$pdo->prepare("UPDATE p50_metric_captures SET confidence=70 WHERE account_id=? AND content_id IS NULL")->execute([$a['accountId']]);
$b=mr_fixture($pdo,$now,'B',100,600,10,60);
mr_fixture($pdo,$now,'C',100,100,10,10);
$d=mr_fixture($pdo,$now,'D',0,300,0,30,true);
$pdo->prepare("UPDATE p50_metric_contents SET published_at=? WHERE id=?")->execute([mr_at($now,-48),$d['contentId']]);
mr_fixture($pdo,$now,'E',0,450,0,45,true);
mr_fixture($pdo,$now,'F',0,999999999,0,999999,true,true);
mr_fixture($pdo,$now,'G',100,900,10,90);
$h=mr_fixture($pdo,$now,'H',100,100,10,10);
$pdo->prepare("DELETE FROM p50_metric_captures WHERE content_id=? AND observed_at>?")->execute([$h['contentId'],mr_at($now,-24)]);

mr_must(array_values(p50_mr_percentiles([10,20]))==[25.0,75.0],'Percentiles de deux profils distincts');
mr_must(array_values(p50_mr_percentiles([10,10]))==[50.0,50.0],'Percentiles de deux profils égaux');
mr_must(array_values(p50_mr_percentiles([10,10,20]))==[25.0,25.0,100.0],'Percentiles de trois profils avec égalité basse');
mr_must(array_values(p50_mr_percentiles([10,20,20]))==[0.0,75.0,75.0],'Percentiles de trois profils avec égalité haute');

$first=p50_mr_calculate($pdo,['24H'],'integration_fixture');
$rows=p50_mr_read($pdo,'24H',100)['rows'];$byId=[];foreach($rows as $row)$byId[$row['profileId']]=$row;
mr_must($byId['A']['rank']<$byId['B']['rank'],'A est devant B au premier calcul');
mr_must($byId['A']['captureCount']===3,'A compte une capture de compte et deux extrémités uniques');
$aComponents=json_decode((string)p50_metrics_value($pdo,"SELECT components_json FROM p50_metric_ranking_current WHERE period_key='24H' AND profile_id='A'"),true);
mr_must(abs((float)$aComponents[0]['raw']['quality']-86.6666666667)<0.001,'La qualité de A moyenne trois captures uniques sans duplication');
mr_must($byId['C']['score']!==null&&!in_array('no_measurable_content',$byId['C']['exclusionReasons'],true),'C conserve son zéro mesuré avec deux extrémités distinctes');
mr_must(!$byId['D']['classable']&&in_array('no_measurable_content',$byId['D']['exclusionReasons'],true),'D reste sans contenu mesurable');
$eRaw=(string)p50_metrics_value($pdo,"SELECT raw_features_json FROM p50_metric_ranking_current WHERE period_key='24H' AND profile_id='E'");
mr_must(str_contains($eRaw,'"publishedInsideWindowFallback":true'),'E utilise le fallback de publication');
$fRaw=(string)p50_metrics_value($pdo,"SELECT raw_features_json FROM p50_metric_ranking_current WHERE period_key='24H' AND profile_id='F'");
mr_must(!$byId['F']['classable']&&in_array('no_measurable_content',$byId['F']['exclusionReasons'],true)&&!str_contains($fRaw,'999999'),'Les valeurs quarantined de F ne contribuent jamais');
mr_must(!$byId['G']['classable']&&$byId['G']['rank']===null&&$byId['G']['score']!==null,'G a un score expérimental sans rang');
mr_must(!$byId['H']['classable']&&$byId['H']['rank']===null&&in_array('no_measurable_content',$byId['H']['exclusionReasons'],true),'H ne transforme pas une capture antérieure unique en zéro mesuré');
$limited=p50_mr_read($pdo,'24H',2);
mr_must(count($limited['rows'])<=2&&$limited['summary']['classable']+$limited['summary']['excluded']===8,'La limite ne réduit pas les KPI globaux');
mr_must(($limited['exclusionSummary']['no_measurable_content']??0)>=3&&($limited['exclusionSummary']['editorial_not_eligible']??0)>=1,'Les exclusions de D, F, G et H restent agrégées hors limite');

p50_metrics_record_capture($pdo,['accountId'=>$b['accountId'],'contentId'=>$b['contentId'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>$now->format('c'),'views'=>5000,'likes'=>500,'comments'=>500,'shares'=>500,'saves'=>500,'confidence'=>99,'provenance'=>['fixture'=>'ranking']]);
$second=p50_mr_calculate($pdo,['24H'],'integration_fixture_second');
$rows2=p50_mr_read($pdo,'24H',100)['rows'];$byId2=[];foreach($rows2 as $row)$byId2[$row['profileId']]=$row;
mr_must($byId2['B']['rank']<$byId2['A']['rank'],'B dépasse A après la nouvelle capture');
mr_must($byId2['B']['previousRank']!==null&&$byId2['B']['rankDelta']>0,'B possède une évolution positive');

$successBefore=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_ranking_runs WHERE algorithm_version=? AND status='success'",[P50_MR_ALGORITHM_VERSION]);
$dueNow=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$skipped=p50_mr_calculate_if_due($pdo,$dueNow,90,'integration-immediate-ranking');
mr_must(($skipped['ok']??false)===true&&($skipped['skipped']??false)===true&&($skipped['reason']??'')==='recent_success','Un succès récent ignore le cycle automatique');
$successAfterSkip=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_ranking_runs WHERE algorithm_version=? AND status='success'",[P50_MR_ALGORITHM_VERSION]);
mr_must($successAfterSkip===$successBefore,'Le skip ne crée aucune nouvelle exécution success');

$agedAt=$dueNow->modify('-121 minutes')->format('Y-m-d H:i:s');
$pdo->prepare("UPDATE p50_metric_ranking_runs SET finished_at=? WHERE algorithm_version=? AND status='success'")->execute([$agedAt,P50_MR_ALGORITHM_VERSION]);
$scheduled=p50_mr_calculate_if_due($pdo,$dueNow,90,'integration-aged-ranking');
mr_must(($scheduled['ok']??false)===true&&($scheduled['skipped']??true)===false,'Un succès ancien déclenche un nouveau calcul');
$scheduledRun=$pdo->prepare("SELECT trigger_type,metadata_json FROM p50_metric_ranking_runs WHERE run_uuid=?");
$scheduledRun->execute([$scheduled['runUuid']]);$scheduledRow=$scheduledRun->fetch();$scheduledMetadata=json_decode((string)$scheduledRow['metadata_json'],true);
mr_must((string)$scheduledRow['trigger_type']==='cron_2h','Le cycle automatique utilise trigger_type cron_2h');
mr_must(($scheduledMetadata['scheduled']??false)===true,'La métadonnée scheduled est vraie');
mr_must(($scheduledMetadata['cadence']??'')==='2h','La métadonnée cadence vaut 2h');
mr_must(($scheduledMetadata['dispatchId']??'')==='integration-aged-ranking','Le dispatchId automatique est conservé');
mr_must(($scheduledMetadata['readOnlyCanonicalInputs']??false)===true&&($scheduledMetadata['publicPublication']??true)===false,'Les garanties expérimentales restent enregistrées');

$periodSummaryCount=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_ranking_period_runs WHERE run_uuid=?",[$scheduled['runUuid']]);
mr_must($periodSummaryCount===5,'Le cycle automatique écrit cinq résumés exacts');
$summaryStmt=$pdo->prepare("SELECT * FROM p50_metric_ranking_period_runs WHERE run_uuid=? AND period_key='24H'");
$summaryStmt->execute([$scheduled['runUuid']]);$storedSummary=$summaryStmt->fetch();
$currentSummaryInput=[];foreach($pdo->query("SELECT score,confidence,coverage,classable,exclusion_reasons_json FROM p50_metric_ranking_current WHERE algorithm_version='MR-V1.0' AND period_key='24H'")->fetchAll() as $row){
    $currentSummaryInput[]=['score'=>$row['score']===null?null:(float)$row['score'],'confidence'=>(float)$row['confidence'],'coverage'=>(float)$row['coverage'],'classable'=>(bool)$row['classable'],'exclusionReasons'=>json_decode((string)$row['exclusion_reasons_json'],true)?:[]];
}
$expectedSummary=p50_mr_period_summary($currentSummaryInput);
mr_must((int)$storedSummary['profiles_considered']===$expectedSummary['profilesConsidered']&&(int)$storedSummary['classable_count']===$expectedSummary['classableCount']&&(int)$storedSummary['excluded_count']===$expectedSummary['excludedCount'],'Le résumé 24H correspond exactement aux lignes courantes');
mr_must(abs((float)$storedSummary['average_confidence']-$expectedSummary['averageConfidence'])<0.001&&abs((float)$storedSummary['average_coverage']-$expectedSummary['averageCoverage'])<0.001,'Les moyennes de confiance et couverture sont exactes');
mr_must((int)$storedSummary['threshold_excluded_count']===$expectedSummary['thresholdExcludedCount']&&(int)$storedSummary['hard_excluded_count']===$expectedSummary['hardExcludedCount']&&(int)$storedSummary['other_excluded_count']===$expectedSummary['otherExcludedCount'],'Les catégories d’exclusion sont exactes');
mr_must(json_decode((string)$storedSummary['exclusion_summary_json'],true)===$expectedSummary['exclusionSummary'],'Le résumé des motifs est valide');
mr_must((int)$storedSummary['classable_count']+(int)$storedSummary['threshold_excluded_count']+(int)$storedSummary['hard_excluded_count']+(int)$storedSummary['other_excluded_count']===(int)$storedSummary['profiles_considered'],'La partition du résumé couvre tous les profils');

$legacyUuid='00000000-0000-4000-8000-000000000024';$legacyAt=$dueNow->modify('-4 hours')->format('Y-m-d H:i:s');
$pdo->prepare("INSERT INTO p50_metric_ranking_runs(run_uuid,algorithm_version,trigger_type,status,periods_json,profiles_considered,classable_count,scores_written,error_message,metadata_json,started_at,finished_at) VALUES(?,?,?,'success',?,8,2,2,NULL,'{}',?,?)")
    ->execute([$legacyUuid,P50_MR_ALGORITHM_VERSION,'legacy_fixture','["24H"]',$legacyAt,$legacyAt]);
$legacySnapshot=$pdo->prepare("INSERT INTO p50_metric_ranking_snapshots(run_uuid,algorithm_version,period_key,profile_id,rank_position,score,confidence,coverage,previous_rank,rank_delta,captured_at) VALUES(?,?,?,?,?,?,?,?,NULL,NULL,?)");
$legacySnapshot->execute([$legacyUuid,P50_MR_ALGORITHM_VERSION,'24H','A',1,80,80,80,$legacyAt]);
$legacySnapshot->execute([$legacyUuid,P50_MR_ALGORITHM_VERSION,'24H','B',2,70,75,75,$legacyAt]);
$failedUuid='00000000-0000-4000-8000-000000000025';
$pdo->prepare("INSERT INTO p50_metric_ranking_runs(run_uuid,algorithm_version,trigger_type,status,periods_json,error_message,metadata_json,started_at,finished_at) VALUES(?,?,?,'failed',?,'fixture','{}',?,?)")
    ->execute([$failedUuid,P50_MR_ALGORITHM_VERSION,'failed_fixture','["24H"]',$legacyAt,$legacyAt]);
mr_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_ranking_period_runs WHERE run_uuid=?",[$failedUuid])===0,'Une exécution échouée ne laisse aucun résumé partiel');

$calibration=p50_mrc_read($pdo,'24H',24);
mr_must(count($calibration['runs'])>=4,'Plusieurs cycles sont retournés');
mr_must(($calibration['runs'][0]['summaryExact']??false)===true,'Le cycle le plus récent possède un résumé exact');
mr_must(count(array_filter($calibration['runs'],fn($run)=>!$run['summaryExact']))>=1,'Un ancien cycle utilise le fallback Top 100');
foreach($calibration['runs'] as $run)foreach(['top10Retention','top50Retention'] as $field)if($run[$field]!==null)mr_must($run[$field]>=0&&$run[$field]<=100,'Les rétentions restent bornées');
mr_must(count($calibration['thresholdSimulation']['cells'])===36,'La matrice contient 36 cellules');
$baselineCell=array_values(array_filter($calibration['thresholdSimulation']['cells'],fn($cell)=>$cell['coverageThreshold']===45&&$cell['confidenceThreshold']===55))[0];
$actualClassable=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_ranking_current WHERE algorithm_version=? AND period_key='24H' AND classable=1",[P50_MR_ALGORITHM_VERSION]);
mr_must($baselineCell['simulatedClassableCount']===$actualClassable&&$calibration['thresholdSimulation']['baseline']['classableCount']===$actualClassable,'La baseline 45/55 égale le classement réellement classable');

$appStateAfter=$pdo->query("SELECT state_json,version FROM app_state WHERE id=1")->fetch();
mr_must($appStateAfter===$appStateBefore,'app_state, les scores publics et les rangs publics restent strictement inchangés');
$serialized=json_encode([$rows2,$calibration,$pdo->query("SELECT components_json,raw_features_json FROM p50_metric_ranking_current")->fetchAll()],JSON_UNESCAPED_SLASHES);
foreach(['payload','source_reference','lock_token','idempotency_key','Bearer ','token=','https://'] as $secret)mr_must(!str_contains($serialized,$secret),'Donnée sensible absente : '.$secret);
mr_must(!str_contains($serialized,'"id":'),'Aucun identifiant de capture dans les composants ou caractéristiques');

echo json_encode(['ok'=>true,'first'=>$first,'second'=>$second,'skipped'=>$skipped,'scheduled'=>$scheduled,'ranks'=>array_map(fn($row)=>[$row['profileId'],$row['rank'],$row['previousRank'],$row['rankDelta']],$rows2)],JSON_UNESCAPED_SLASHES).PHP_EOL;
