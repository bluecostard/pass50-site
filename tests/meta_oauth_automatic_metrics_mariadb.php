<?php
declare(strict_types=1);

$dsn=getenv('P50_TEST_DSN')?:'';$dbUser=getenv('P50_TEST_DB_USER')?:'root';$password=getenv('P50_TEST_DB_PASSWORD')?:'';
if($dsn===''){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(2);}
$pdo=new PDO($dsn,$dbUser,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $pdo;return $pdo;}
function meta_metrics_must(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
function meta_metrics_response(array $payload,string $url,int $status=200): array {return ['status'=>$status,'body'=>json_encode($payload,JSON_UNESCAPED_SLASHES),'url'=>$url,'error'=>''];}

$config=[
  'app'=>['base_url'=>'https://www.pass50.store'],
  'data_engine'=>['confidence_threshold'=>80,'live_stale_minutes'=>3],
  'metrics'=>['orchestrator_enabled'=>true,'cron_secret'=>hash('sha256','meta-metrics-fixture')],
  'meta_oauth'=>[
    'app_id'=>'123456789','app_secret'=>str_repeat('s',40),'configuration_id'=>'cfg_meta_metrics',
    'redirect_uri'=>'https://www.pass50.store/api/meta-oauth-callback.php','graph_version'=>'v25.0',
    'token_encryption_key'=>base64_encode(str_repeat('m',32)),
  ],
];

foreach(['p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_meta_oauth_assets','p50_meta_oauth_connections','p50_meta_deletion_requests','p50_social_links','p50_profile_registry','p50_live_streams'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci PRIMARY KEY,public_name VARCHAR(190),alive TINYINT NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE p50_social_links(profile_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,platform VARCHAR(32),normalized_url TEXT,confidence INT,status VARCHAR(24),PRIMARY KEY(profile_id,platform))");
$pdo->prepare('INSERT INTO p50_profile_registry(profile_id,public_name,alive) VALUES(?,?,1)')->execute(['profile-meta-1','Profil Meta 1']);

require dirname(__DIR__).'/api/metrics-collectors-core.php';
require dirname(__DIR__).'/api/metrics-orchestrator-core.php';
p50mo_ensure_schema();p50_metrics_ensure_schema($pdo);

$token='page-token-meta-fixture';
$pdo->prepare("INSERT INTO p50_meta_oauth_connections(user_id,meta_user_id,meta_user_name,access_token_encrypted,scopes,token_expires_at,status) VALUES(?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),'active')")
    ->execute(['meta-user-1','meta-id-1','Meta Fixture',p50mo_encrypt('user-token'),'pages_show_list pages_read_engagement instagram_basic']);
$insert=$pdo->prepare("INSERT INTO p50_meta_oauth_assets(user_id,platform,asset_id,profile_id,asset_name,username,profile_url,parent_page_id,access_token_encrypted,status) VALUES(?,?,?,?,?,?,?,?,?,'active')");
$insert->execute(['meta-user-1','Facebook','page-1','profile-meta-1','Page Fixture','','https://www.facebook.com/page-1',null,p50mo_encrypt($token)]);
$insert->execute(['meta-user-1','Instagram','ig-1','profile-meta-1','Instagram Fixture','creator_fixture','https://www.instagram.com/creator_fixture/','page-1',p50mo_encrypt($token)]);

$fbCredentials=p50_mc_credentials('Facebook','profile-meta-1');$igCredentials=p50_mc_credentials('Instagram','profile-meta-1');
meta_metrics_must($fbCredentials['secret']===$token&&$fbCredentials['pageId']==='page-1','Le jeton de Page Facebook doit venir de l’actif OAuth.');
meta_metrics_must($igCredentials['secret']===$token&&$igCredentials['accountId']==='ig-1','Le compte Instagram doit utiliser le jeton de Page associé.');
meta_metrics_must($fbCredentials['graphVersion']==='v25.0'&&$igCredentials['graphVersion']==='v25.0','La version Graph configurée doit être utilisée.');
meta_metrics_must($fbCredentials['insightsAuthorized']===false&&$igCredentials['insightsAuthorized']===false,'Les Insights ne doivent pas être supposées sans permission explicite.');

$fbOfficial=p50_mc_official($pdo,'profile-meta-1','Facebook');$igOfficial=p50_mc_official($pdo,'profile-meta-1','Instagram');
meta_metrics_must(($fbOfficial['source_type']??'')==='meta_oauth_mapping'&&($igOfficial['source_type']??'')==='meta_oauth_mapping','Les actifs Meta associés doivent constituer la source officielle.');

$selection=p50_mo_select($pdo,'p0',['now'=>'2026-07-30T01:00:00Z']);
$selected=array_map(static fn($row)=>$row['profileId'].'|'.$row['platform'],$selection['candidates']);
meta_metrics_must(in_array('profile-meta-1|Facebook',$selected,true)&&in_array('profile-meta-1|Instagram',$selected,true),'P0 doit inclure les actifs Meta OAuth associés sans lien manuel.');

$first=p50_mo_enqueue_profile($pdo,'profile-meta-1','Facebook','p0',['now'=>'2026-07-30T01:00:00Z','reason'=>'meta_oauth_mapping']);
$duplicate=p50_mo_enqueue_profile($pdo,'profile-meta-1','Facebook','p0',['now'=>'2026-07-30T01:00:00Z','reason'=>'meta_oauth_mapping']);
meta_metrics_must($first['created']===true&&$duplicate['duplicate']===true,'La tâche immédiate Meta doit être idempotente.');

$requests=[];
$fetch=function(string $url,array $headers=[],string $method='GET',?array $json=null) use(&$requests): array {
    $requests[]=$url;$path=(string)parse_url($url,PHP_URL_PATH);
    if($path==='/v25.0/page-1')return meta_metrics_response(['id'=>'page-1','name'=>'Page Fixture','username'=>'page_fixture','followers_count'=>125,'fan_count'=>120],$url);
    if($path==='/v25.0/page-1/posts')return meta_metrics_response(['data'=>[['id'=>'page-1_post-1','message'=>'Publication Meta','created_time'=>'2026-07-30T00:30:00Z','permalink_url'=>'https://www.facebook.com/page-1/posts/post-1','status_type'=>'mobile_status_update','attachments'=>['data'=>[['media_type'=>'photo']]],'comments'=>['summary'=>['total_count'=>4]],'shares'=>['count'=>2],'like_reactions'=>['summary'=>['total_count'=>8]],'reactions'=>['summary'=>['total_count'=>10]]]]],$url);
    if($path==='/v25.0/ig-1')return meta_metrics_response(['id'=>'ig-1','username'=>'creator_fixture','account_type'=>'BUSINESS','followers_count'=>250,'follows_count'=>50,'media_count'=>1],$url);
    if($path==='/v25.0/ig-1/media')return meta_metrics_response(['data'=>[['id'=>'ig-media-1','caption'=>'Publication Instagram','media_type'=>'IMAGE','media_product_type'=>'FEED','permalink'=>'https://www.instagram.com/p/meta1/','timestamp'=>'2026-07-30T00:35:00Z','like_count'=>12,'comments_count'=>3]]],$url);
    return meta_metrics_response(['error'=>['message'=>'fixture endpoint absent']],$url,404);
};

$facebook=p50_metrics_collect_profile($pdo,'profile-meta-1','Facebook',2,$fetch,'2026-07-30T01:05:00Z');
$instagram=p50_metrics_collect_profile($pdo,'profile-meta-1','Instagram',2,$fetch,'2026-07-30T01:05:00Z');
meta_metrics_must($facebook['accountFound']&&$facebook['capturesRecorded']>=2,'Facebook OAuth doit enregistrer le compte et sa publication.');
meta_metrics_must($instagram['accountFound']&&$instagram['capturesRecorded']>=2,'Instagram OAuth doit enregistrer le compte et son média.');
meta_metrics_must(!array_filter($requests,static fn($url)=>str_contains($url,'/insights')),'Aucun endpoint Insights ne doit être appelé sans permission explicite.');
meta_metrics_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_accounts WHERE profile_id='profile-meta-1' AND platform IN ('Facebook','Instagram')")===2,'Deux comptes canoniques Meta doivent être créés.');

$safe=p50mm_safe_status($pdo);$encoded=json_encode($safe,JSON_UNESCAPED_SLASHES);
meta_metrics_must($safe['summary']['mapped']===2&&$safe['summary']['insightsAuthorized']===0,'Le diagnostic Meta doit distinguer base et Insights.');
meta_metrics_must(!str_contains($encoded,$token)&&!str_contains($encoded,'meta-user-1')&&!str_contains($encoded,'user-token'),'Le diagnostic ne doit exposer aucun jeton ni identifiant utilisateur OAuth.');

echo json_encode(['ok'=>true,'automaticEligibility'=>true,'baseCollection'=>true,'insightsGuarded'=>true,'safeStatus'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
