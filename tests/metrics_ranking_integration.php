<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/metrics-ranking-core.php';

$dsn=getenv('P50_TEST_DSN');if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function mr_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

foreach(['p50_metric_ranking_snapshots','p50_metric_ranking_current','p50_metric_ranking_runs','p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190) NOT NULL,handle VARCHAR(190) NOT NULL DEFAULT '',region VARCHAR(32) NOT NULL DEFAULT 'CI',category VARCHAR(100) NOT NULL DEFAULT '',alive TINYINT NOT NULL DEFAULT 1,eligible TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE app_state(id INT PRIMARY KEY,state_json LONGTEXT NOT NULL,version INT NOT NULL)");
$pdo->exec("INSERT INTO app_state VALUES(1,'{\"rankingExperimentalSentinel\":true}',731)");
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

mr_must(array_values(p50_mr_percentiles([10,20]))===[25.0,75.0],'Percentiles de deux profils distincts');
mr_must(array_values(p50_mr_percentiles([10,10]))===[50.0,50.0],'Percentiles de deux profils égaux');
mr_must(array_values(p50_mr_percentiles([10,10,20]))===[25.0,25.0,100.0],'Percentiles de trois profils avec égalité basse');
mr_must(array_values(p50_mr_percentiles([10,20,20]))===[0.0,75.0,75.0],'Percentiles de trois profils avec égalité haute');

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
mr_must((string)p50_metrics_value($pdo,"SELECT state_json FROM app_state WHERE id=1")==='{"rankingExperimentalSentinel":true}'&&(int)p50_metrics_value($pdo,"SELECT version FROM app_state WHERE id=1")===731,'app_state reste strictement inchangé');
$serialized=json_encode([$rows2,$pdo->query("SELECT components_json,raw_features_json FROM p50_metric_ranking_current")->fetchAll()],JSON_UNESCAPED_SLASHES);
foreach(['payload','source_reference','lock_token','idempotency_key','Bearer ','token=','https://'] as $secret)mr_must(!str_contains($serialized,$secret),'Donnée sensible absente : '.$secret);
mr_must(!str_contains($serialized,'"id":'),'Aucun identifiant de capture dans les composants ou caractéristiques');

echo json_encode(['ok'=>true,'first'=>$first,'second'=>$second,'ranks'=>array_map(fn($row)=>[$row['profileId'],$row['rank'],$row['previousRank'],$row['rankDelta']],$rows2)],JSON_UNESCAPED_SLASHES).PHP_EOL;
