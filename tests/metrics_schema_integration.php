<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-schema-core.php';

function it_assert(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}

$dsn=(string)(getenv('P50_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=pass50_test;charset=utf8mb4');
$pdo=new PDO($dsn,(string)(getenv('P50_TEST_DB_USER')?:'root'),(string)(getenv('P50_TEST_DB_PASSWORD')?:''),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

$pdo->exec("CREATE TABLE IF NOT EXISTS app_state(id VARCHAR(32) PRIMARY KEY,data LONGTEXT NOT NULL)");
$pdo->exec("INSERT INTO app_state(id,data) VALUES('public','{\"ranking\":\"unchanged\"}') ON DUPLICATE KEY UPDATE data=data");
$pdo->exec("CREATE TABLE IF NOT EXISTS p50_social_links(profile_id VARCHAR(100),platform VARCHAR(32),normalized_url TEXT,confidence INT,status VARCHAR(24),verified_at DATETIME NULL,updated_at DATETIME NULL,PRIMARY KEY(profile_id,platform))");
$pdo->exec("CREATE TABLE IF NOT EXISTS p50_activity_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),platform VARCHAR(32),event_type VARCHAR(48),title VARCHAR(255),url TEXT,url_hash CHAR(64),published_at DATETIME NULL,metrics LONGTEXT NULL,confidence INT,status VARCHAR(24),collected_at DATETIME)");
$pdo->exec("CREATE TABLE IF NOT EXISTS p50_activity_metric_history(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),platform VARCHAR(32),url_hash CHAR(64),metrics LONGTEXT,captured_at DATETIME)");

$first=p50_metrics_ensure_schema($pdo);$second=p50_metrics_ensure_schema($pdo);
it_assert($first['status']==='applied'&&$second['status']==='applied','Migration non rejouable');
foreach(['p50_metric_schema_migrations','p50_metric_accounts','p50_metric_contents','p50_metric_captures','p50_metric_jobs','p50_metric_runs'] as $table)it_assert(p50_metrics_table_exists($pdo,$table),'Table absente: '.$table);

$keyA=p50_metrics_account_key('p1','YouTube',null,'@Creator','https://youtube.com/@Creator?utm_source=x');
$keyB=p50_metrics_account_key('p1','YouTube',null,'creator','https://www.youtube.com/@Creator/');
it_assert($keyA===$keyB,'account_key instable');
$urlContentA=p50_metrics_content_key($keyA,'X',null,'https://x.com/creator/status/42?utm_source=test');
$urlContentB=p50_metrics_content_key($keyA,'X',null,'https://www.x.com/creator/status/42/');
it_assert($urlContentA===$urlContentB,'Variantes URL de contenu non dédupliquées');
$account=p50_metrics_upsert_account($pdo,['profileId'=>'p1','platform'=>'YouTube','handle'=>'@Creator','canonicalUrl'=>'https://www.youtube.com/@Creator/?utm_source=x','confidence'=>98,'sourceType'=>'fixture','provenance'=>['fixture'=>'account']]);
$accountAgain=p50_metrics_upsert_account($pdo,['profileId'=>'p1','platform'=>'YouTube','handle'=>'creator','canonicalUrl'=>'https://youtube.com/@Creator','confidence'=>98,'sourceType'=>'fixture','provenance'=>['fixture'=>'account']]);
it_assert($account['id']===$accountAgain['id'],'Compte dupliqué');

$profileRejected=false;try{p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'canonicalUrl'=>'https://youtube.com/@Creator','sourceType'=>'fixture','provenance'=>['fixture'=>'profile']]);}catch(InvalidArgumentException){$profileRejected=true;}
it_assert($profileRejected,'Page de profil acceptée comme contenu');
$content=p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'platformContentId'=>'abc123xyz','contentType'=>'video','canonicalUrl'=>'https://youtube.com/watch?v=abc123xyz&utm_source=x','title'=>'Fixture','sourceType'=>'fixture','provenance'=>['fixture'=>'content']]);
$contentAgain=p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'platformContentId'=>'abc123xyz','contentType'=>'video','canonicalUrl'=>'https://www.youtube.com/watch?v=abc123xyz','title'=>'Fixture','sourceType'=>'fixture','provenance'=>['fixture'=>'content']]);
it_assert($content['id']===$contentAgain['id'],'Contenu dupliqué');

$base=['accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'fixture','sourceType'=>'official_api','observedAt'=>'2026-07-28T10:00:00Z','confidence'=>98,'provenance'=>['fixture'=>'capture']];
$capture=p50_metrics_record_capture($pdo,$base+['views'=>null,'likes'=>0,'metrics'=>['futureMetric'=>12]]);
$duplicate=p50_metrics_record_capture($pdo,$base+['views'=>null,'likes'=>0,'metrics'=>['futureMetric'=>12],'runUuid'=>p50_metrics_uuid()]);
it_assert($capture['created']&&!$duplicate['created']&&$duplicate['duplicate'],'Doublon de capture non ignoré');
$later=p50_metrics_record_capture($pdo,array_replace($base,['views'=>null,'likes'=>0,'metrics'=>['futureMetric'=>12],'observedAt'=>'2026-07-28T12:00:00Z']));
$changed=p50_metrics_record_capture($pdo,$base+['views'=>1,'likes'=>0,'metrics'=>['futureMetric'=>12]]);
it_assert($later['created']&&$changed['created'],'Nouvelle observation ou valeur ignorée');
$zero=p50_metrics_record_capture($pdo,$base+['views'=>0,'likes'=>null,'metrics'=>[]]);
it_assert($zero['created'],'NULL et zéro confondus');
$bad=p50_metrics_record_capture($pdo,$base+['views'=>-1,'metrics'=>[]]);
it_assert($bad['quarantined']&&$bad['usableMetricCount']===0,'Valeur négative non quarantainée');
$missingProvenance=false;try{p50_metrics_record_capture($pdo,array_replace($base,['views'=>2,'provenance'=>[]]));}catch(InvalidArgumentException){$missingProvenance=true;}
it_assert($missingProvenance,'Provenance facultative');
$secretRejected=false;try{p50_metrics_record_capture($pdo,$base+['views'=>2,'metadata'=>['authorization'=>'Bearer forbidden']]);}catch(InvalidArgumentException){$secretRejected=true;}
it_assert($secretRejected,'Secret accepté');

$immutable=false;try{$pdo->exec("UPDATE p50_metric_captures SET views=999 WHERE id=".(int)$capture['id']);}catch(PDOException){$immutable=true;}
it_assert($immutable,'Capture modifiable');

$job=p50_metrics_enqueue_job($pdo,['idempotencyKey'=>'fixture-job','collector'=>'fixture','scopeType'=>'profile','scopeId'=>'p1','payload'=>['safe'=>true]]);
$jobDuplicate=p50_metrics_enqueue_job($pdo,['idempotencyKey'=>'fixture-job','collector'=>'fixture','scopeType'=>'profile','scopeId'=>'p1','payload'=>['safe'=>true]]);
it_assert($job['created']&&$jobDuplicate['duplicate']&&$job['jobUuid']===$jobDuplicate['jobUuid'],'Job non idempotent');
$run=p50_metrics_start_run($pdo,['collector'=>'fixture','triggerType'=>'integration','metadata'=>['safe'=>true]]);
$finished=p50_metrics_finish_run($pdo,$run['runUuid'],'success',['accountsProcessed'=>1],null,['safe'=>true]);
it_assert($finished['finished'],'Run non terminé');

$legacyHash=hash('sha256','legacy-event-key');
$pdo->exec("INSERT IGNORE INTO p50_social_links VALUES('legacy','X','https://x.com/legacy',95,'verified',NOW(),NOW())");
$stmt=$pdo->prepare("INSERT INTO p50_activity_events(profile_id,platform,event_type,title,url,url_hash,published_at,metrics,confidence,status,collected_at) VALUES('legacy','X','post','Legacy','https://x.com/legacy/status/123',?,NOW(),'{\"views\":10,\"likes\":0}',95,'verified',NOW())");$stmt->execute([$legacyHash]);
$stmt=$pdo->prepare("INSERT INTO p50_activity_metric_history(profile_id,platform,url_hash,metrics,captured_at) VALUES('legacy','X',?,'{\"views\":10,\"likes\":0}',NOW())");$stmt->execute([$legacyHash]);
$beforeState=(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn();
$backfillOne=p50_metrics_backfill_legacy($pdo,1000);
$countsOne=[(int)$pdo->query("SELECT COUNT(*) FROM p50_metric_accounts")->fetchColumn(),(int)$pdo->query("SELECT COUNT(*) FROM p50_metric_contents")->fetchColumn(),(int)$pdo->query("SELECT COUNT(*) FROM p50_metric_captures")->fetchColumn()];
$backfillTwo=p50_metrics_backfill_legacy($pdo,1000);
$countsTwo=[(int)$pdo->query("SELECT COUNT(*) FROM p50_metric_accounts")->fetchColumn(),(int)$pdo->query("SELECT COUNT(*) FROM p50_metric_contents")->fetchColumn(),(int)$pdo->query("SELECT COUNT(*) FROM p50_metric_captures")->fetchColumn()];
it_assert($countsOne===$countsTwo,'Deuxième backfill non idempotent');
it_assert($beforeState===(string)$pdo->query("SELECT data FROM app_state WHERE id='public'")->fetchColumn(),'app_state modifié');

echo json_encode(['ok'=>true,'schema'=>$first,'backfillFirst'=>$backfillOne,'backfillSecond'=>$backfillTwo,'totals'=>['accounts'=>$countsTwo[0],'contents'=>$countsTwo[1],'captures'=>$countsTwo[2]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
