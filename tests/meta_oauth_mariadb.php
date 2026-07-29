<?php
declare(strict_types=1);
$dsn=getenv('P50_TEST_DSN')?:'';$user=getenv('P50_TEST_DB_USER')?:'root';$password=getenv('P50_TEST_DB_PASSWORD')?:'';if($dsn===''){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(2);} $pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $pdo;return $pdo;}
$config=['app'=>['base_url'=>'https://www.pass50.store'],'meta_oauth'=>['app_id'=>'123','app_secret'=>str_repeat('s',40),'configuration_id'=>'cfg_pass50_test','redirect_uri'=>'https://www.pass50.store/api/meta-oauth-callback.php','graph_version'=>'v25.0','token_encryption_key'=>base64_encode(str_repeat('m',32))]];
require dirname(__DIR__).'/api/meta-oauth-core.php';
function must(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
p50mo_ensure_schema();p50mo_ensure_schema();
$pdo->exec("CREATE TABLE IF NOT EXISTS p50_live_streams (stream_key CHAR(64) PRIMARY KEY,profile_id VARCHAR(100) NOT NULL,platform VARCHAR(32) NOT NULL,source VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec('DELETE FROM p50_meta_deletion_requests');$pdo->exec('DELETE FROM p50_meta_oauth_assets');$pdo->exec('DELETE FROM p50_meta_oauth_connections');$pdo->exec('DELETE FROM p50_live_streams');
$pdo->prepare("INSERT INTO p50_meta_oauth_connections(user_id,meta_user_id,meta_user_name,access_token_encrypted,scopes,token_expires_at,status) VALUES(?,?,?,?,?,?, 'active')")->execute(['u1','m1','Meta Test',p50mo_encrypt('user-token'),'pages_show_list pages_read_engagement instagram_basic',gmdate('Y-m-d H:i:s',time()+3600)]);
$pdo->prepare("INSERT INTO p50_meta_oauth_assets(user_id,platform,asset_id,profile_id,asset_name,username,access_token_encrypted,status) VALUES(?,?,?,?,?,?,?,'active')")->execute(['u1','Instagram','ig1','profile1','Compte IG','compte_ig',p50mo_encrypt('page-token')]);
$pdo->prepare("INSERT INTO p50_live_streams(stream_key,profile_id,platform,source,status) VALUES(?,?,?,?,?)")->execute([str_repeat('a',64),'profile1','Instagram','meta_authorized','live']);
must((int)$pdo->query('SELECT COUNT(*) FROM p50_meta_oauth_connections')->fetchColumn()===1,'Connexion Meta persistée.');must((int)$pdo->query('SELECT COUNT(*) FROM p50_meta_oauth_assets')->fetchColumn()===1,'Actif Meta persisté.');must(p50mo_decrypt((string)$pdo->query('SELECT access_token_encrypted FROM p50_meta_oauth_assets LIMIT 1')->fetchColumn())==='page-token','Jeton actif chiffré.');
$code=bin2hex(random_bytes(16));must(p50mo_delete_local_data_for_meta_user('m1',$code)===1,'Une connexion Meta doit être supprimée.');
must((int)$pdo->query('SELECT COUNT(*) FROM p50_meta_oauth_connections')->fetchColumn()===0,'Connexion supprimée.');must((int)$pdo->query('SELECT COUNT(*) FROM p50_meta_oauth_assets')->fetchColumn()===0,'Actifs supprimés.');must((int)$pdo->query("SELECT COUNT(*) FROM p50_live_streams WHERE source='meta_authorized'")->fetchColumn()===0,'Lives autorisés supprimés.');
$stmt=$pdo->prepare('SELECT status FROM p50_meta_deletion_requests WHERE confirmation_code=?');$stmt->execute([$code]);must($stmt->fetchColumn()==='completed','Demande de suppression tracée sans identifiant Meta brut.');
echo json_encode(['ok'=>true,'schemaIdempotent'=>true,'encryptedAssetToken'=>true,'deletionCompleted'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
