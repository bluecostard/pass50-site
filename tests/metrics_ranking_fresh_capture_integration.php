<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/metrics-ranking-fresh-capture-core.php';

$dsn=getenv('P50_TEST_DSN');
if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
function fresh_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}

foreach([
    'p50_metric_ranking_period_runs','p50_metric_ranking_snapshots','p50_metric_ranking_current','p50_metric_ranking_runs',
    'p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts',
    'p50_metric_schema_migrations','p50_profile_registry','app_state',
] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");

$pdo->exec("CREATE TABLE p50_profile_registry(
    profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190) NOT NULL,handle VARCHAR(190) NOT NULL DEFAULT '',
    region VARCHAR(32) NOT NULL DEFAULT 'CI',category VARCHAR(100) NOT NULL DEFAULT '',alive TINYINT NOT NULL DEFAULT 1,
    eligible TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE app_state(id INT PRIMARY KEY,state_json LONGTEXT NOT NULL,version INT NOT NULL)");
$pdo->exec("INSERT INTO app_state VALUES(1,'{\"freshCaptureSentinel\":true}',901)");
$appStateBefore=$pdo->query("SELECT state_json,version FROM app_state WHERE id=1")->fetch();
$pdo->exec("INSERT INTO p50_profile_registry VALUES('A','Profil A','@a','CI','Fixture',1,1)");

p50_metrics_ensure_schema($pdo);
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$at=static fn(int $minutes): string=>$now->modify(($minutes>=0?'+':'').$minutes.' minutes')->format('Y-m-d H:i:s');
$account=p50_metrics_upsert_account($pdo,[
    'profileId'=>'A','platform'=>'YouTube','platformAccountId'=>'UCA','canonicalUrl'=>'https://youtube.com/@fixturea',
    'status'=>'active','confidence'=>95,'sourceType'=>'manual_owner','observedAt'=>$at(-1600),'provenance'=>['fixture'=>'fresh-capture'],
]);
$content=p50_metrics_upsert_content($pdo,[
    'accountId'=>$account['id'],'platformContentId'=>'video-a','canonicalUrl'=>'https://youtube.com/watch?v=fixturea',
    'contentType'=>'video','publishedAt'=>$at(-3000),'status'=>'active','confidence'=>95,'sourceType'=>'manual_owner',
    'observedAt'=>$at(-1600),'provenance'=>['fixture'=>'fresh-capture'],
]);
p50_metrics_record_capture($pdo,[
    'accountId'=>$account['id'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>$at(-60),
    'followers'=>1000,'confidence'=>95,'provenance'=>['fixture'=>'fresh-capture'],
]);
p50_metrics_record_capture($pdo,[
    'accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>$at(-1500),
    'views'=>100,'likes'=>10,'comments'=>10,'shares'=>10,'saves'=>10,'confidence'=>95,'provenance'=>['fixture'=>'fresh-capture'],
]);
p50_metrics_record_capture($pdo,[
    'accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>$at(-60),
    'views'=>1000,'likes'=>100,'comments'=>100,'shares'=>100,'saves'=>100,'confidence'=>95,'provenance'=>['fixture'=>'fresh-capture'],
]);

$first=p50_mr_calculate($pdo,['24H'],'fresh_capture_fixture_initial');
fresh_must(($first['ok']??false)===true,'Le premier calcul MR doit réussir');
$recentFinished=$now->modify('-30 minutes')->format('Y-m-d H:i:s');
$pdo->prepare("UPDATE p50_metric_ranking_runs SET finished_at=? WHERE algorithm_version=? AND status='success'")
    ->execute([$recentFinished,P50_MR_ALGORITHM_VERSION]);

$successBefore=(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_ranking_runs WHERE algorithm_version=? AND status='success'",[P50_MR_ALGORITHM_VERSION]);
$withoutFresh=p50_mr_calculate_if_due_with_fresh_captures($pdo,$now,90,'fresh-no-new-capture');
fresh_must(($withoutFresh['skipped']??false)===true&&($withoutFresh['reason']??'')==='recent_success','Sans nouvelle capture, le succès récent reste protégé');
fresh_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_ranking_runs WHERE algorithm_version=? AND status='success'",[P50_MR_ALGORITHM_VERSION])===$successBefore,'Le skip ne crée aucun run');

p50_metrics_record_capture($pdo,[
    'accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'fixture','sourceType'=>'fixture','observedAt'=>$at(-1),
    'views'=>1600,'likes'=>160,'comments'=>160,'shares'=>160,'saves'=>160,'confidence'=>99,'provenance'=>['fixture'=>'fresh-capture'],
]);
$withFresh=p50_mr_calculate_if_due_with_fresh_captures($pdo,$now,90,'fresh-capture-override');
fresh_must(($withFresh['ok']??false)===true&&($withFresh['skipped']??true)===false,'Une capture plus récente doit autoriser le recalcul');
fresh_must(($withFresh['freshCaptureOverride']??false)===true,'Le recalcul doit signaler le passage par le garde de fraîcheur');
fresh_must(($withFresh['freshCaptureGateVersion']??'')===P50_MR_FRESH_CAPTURE_GATE_VERSION,'La version du garde doit être exposée');
fresh_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_ranking_runs WHERE algorithm_version=? AND status='success'",[P50_MR_ALGORITHM_VERSION])===$successBefore+1,'Un nouveau run MR doit être écrit');

$afterFreshNow=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$secondSkip=p50_mr_calculate_if_due_with_fresh_captures($pdo,$afterFreshNow,90,'fresh-second-skip');
fresh_must(($secondSkip['skipped']??false)===true&&($secondSkip['reason']??'')==='recent_success','Après intégration, le garde anti-doublon redevient actif');
$appStateAfter=$pdo->query("SELECT state_json,version FROM app_state WHERE id=1")->fetch();
fresh_must($appStateAfter===$appStateBefore,'Le recalcul expérimental ne modifie jamais app_state');

echo "metrics ranking fresh capture integration ok\n";
