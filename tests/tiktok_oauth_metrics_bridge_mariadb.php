<?php
declare(strict_types=1);

$dsn=getenv('P50_TEST_DSN')?:'';
$user=getenv('P50_TEST_DB_USER')?:'';
$password=getenv('P50_TEST_DB_PASSWORD')?:'';
if($dsn==='')throw new RuntimeException('P50_TEST_DSN absent.');

$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['pdo']=$pdo;
function db(): PDO {return $GLOBALS['pdo'];}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['p50_tiktok_oauth_videos','p50_tiktok_oauth_connections','p50_social_links','p50_profile_registry','users'] as $table)$pdo->exec("DROP TABLE IF EXISTS $table");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users(id CHAR(36) PRIMARY KEY,email VARCHAR(255) NOT NULL,deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(255) NOT NULL,alive TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE p50_social_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100) NOT NULL,platform VARCHAR(40) NOT NULL,normalized_url TEXT NOT NULL,confidence SMALLINT NOT NULL,status VARCHAR(30) NOT NULL,INDEX idx_link(profile_id,platform,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT INTO users(id,email) VALUES('00000000-0000-0000-0000-000000000001','papa-ado@example.test')");
$pdo->exec("INSERT INTO p50_profile_registry(profile_id,public_name,alive) VALUES('papa-ado','Papa Ado',1)");
$pdo->exec("INSERT INTO p50_social_links(profile_id,platform,normalized_url,confidence,status) VALUES('papa-ado','TikTok','https://www.tiktok.com/@ckng12',99,'verified')");

require __DIR__.'/../api/metrics-schema-core.php';
require __DIR__.'/../api/tiktok-oauth-core.php';
require __DIR__.'/../api/tiktok-metrics-bridge-core.php';
p50tk_ensure_schema();

$insert=$pdo->prepare("INSERT INTO p50_tiktok_oauth_connections
(user_id,open_id,union_id,display_name,username,access_token_encrypted,refresh_token_encrypted,token_type,scopes,access_expires_at,refresh_expires_at,status)
VALUES(?,?,?,?,?,?,?,?,?,?,?,'active')");
$insert->execute([
 '00000000-0000-0000-0000-000000000001','open-papa-ado','union-papa-ado','CKNG12','CKNG12',
 'v1.test-access','v1.test-refresh','Bearer','user.info.basic user.info.profile user.info.stats video.list',
 gmdate('Y-m-d H:i:s',time()+3600),gmdate('Y-m-d H:i:s',time()+86400),
]);

$connection=p50tm_connection_for_profile($pdo,'papa-ado',true);
if(!$connection||(string)$connection['open_id']!=='open-papa-ado')throw new RuntimeException('Association TikTok officielle non reconnue.');
$access=p50tm_public_access('papa-ado');
if(empty($access['configured'])||empty($access['authorized'])||($access['mode']??'')!=='authorized_display')throw new RuntimeException('Accès OAuth TikTok non activé.');
$ids=p50tm_authorized_profile_ids($pdo);
if($ids!==['papa-ado'])throw new RuntimeException('Profil TikTok autorisé absent de la priorité OAuth.');

$pdo->exec("UPDATE p50_social_links SET status='candidate' WHERE profile_id='papa-ado' AND platform='TikTok'");
if(p50tm_connection_for_profile($pdo,'papa-ado',true)!==null)throw new RuntimeException('Lien TikTok non validé accepté.');
$pdo->exec("UPDATE p50_social_links SET status='verified' WHERE profile_id='papa-ado' AND platform='TikTok'");
$pdo->exec("UPDATE p50_tiktok_oauth_connections SET username='autre_compte' WHERE open_id='open-papa-ado'");
if(p50tm_connection_for_profile($pdo,'papa-ado',true)!==null)throw new RuntimeException('Compte TikTok différent associé à Papa Ado.');

$pdo->exec("UPDATE p50_tiktok_oauth_connections SET username='CKNG12',status='reauthorization_required' WHERE open_id='open-papa-ado'");
$access=p50tm_public_access('papa-ado');
if(empty($access['configured'])||!empty($access['authorized'])||empty($access['authorizationRequired']))throw new RuntimeException('Réautorisation TikTok mal signalée.');

echo "TikTok OAuth metrics bridge: OK\n";
