<?php
declare(strict_types=1);

$dsn=getenv('P50_TEST_DSN')?:'';$dbUser=getenv('P50_TEST_DB_USER')?:'root';$password=getenv('P50_TEST_DB_PASSWORD')?:'';
if($dsn===''){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(2);}
$pdo=new PDO($dsn,$dbUser,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {global $pdo;return $pdo;}
function must(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
$config=[
    'google_oauth'=>[
        'client_id'=>'client-test.apps.googleusercontent.com','client_secret'=>str_repeat('s',40),
        'redirect_uri'=>'https://www.pass50.store/api/youtube-oauth-callback.php','token_encryption_key'=>base64_encode(str_repeat('y',32)),
    ],
    'data_engine'=>['confidence_threshold'=>80],
];

$pdo->exec("CREATE TABLE IF NOT EXISTS users (id CHAR(36) PRIMARY KEY,email VARCHAR(190) NOT NULL DEFAULT '',display_name VARCHAR(190) NOT NULL DEFAULT '',role VARCHAR(24) NOT NULL DEFAULT 'user',email_confirmed_at DATETIME NULL,deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS p50_profile_registry (profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190) NOT NULL,handle VARCHAR(190) NOT NULL DEFAULT '',region VARCHAR(32) NOT NULL DEFAULT 'CI',category VARCHAR(100) NOT NULL DEFAULT '',alive TINYINT(1) NOT NULL DEFAULT 1,eligible TINYINT(1) NOT NULL DEFAULT 1,state_hash CHAR(64) NOT NULL,last_state_sync_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

require dirname(__DIR__).'/api/youtube-metrics-bridge-core.php';
p50ym_ensure_schema($pdo);p50ym_ensure_schema($pdo);p50ya_ensure_schema();

$pdo->exec('DELETE FROM p50_youtube_analytics_snapshots');
$pdo->exec('DELETE FROM p50_youtube_oauth_connections');
$pdo->exec("DELETE FROM users WHERE id IN ('yt-user-1','yt-user-2')");
$pdo->exec("DELETE FROM p50_profile_registry WHERE profile_id IN ('profile-youtube-1','profile-youtube-2')");
$pdo->prepare('INSERT INTO users(id,email,display_name,role) VALUES(?,?,?,?)')->execute(['yt-user-1','one@example.test','One','owner']);
$pdo->prepare('INSERT INTO users(id,email,display_name,role) VALUES(?,?,?,?)')->execute(['yt-user-2','two@example.test','Two','admin']);
$pdo->prepare('INSERT INTO p50_profile_registry(profile_id,public_name,state_hash) VALUES(?,?,?)')->execute(['profile-youtube-1','Profil YouTube 1',str_repeat('a',64)]);
$pdo->prepare('INSERT INTO p50_profile_registry(profile_id,public_name,state_hash) VALUES(?,?,?)')->execute(['profile-youtube-2','Profil YouTube 2',str_repeat('b',64)]);

$insert=$pdo->prepare("INSERT INTO p50_youtube_oauth_connections(user_id,channel_id,channel_title,channel_custom_url,channel_thumbnail_url,access_token_encrypted,refresh_token_encrypted,token_type,scopes,access_expires_at,status) VALUES(?,?,?,?,?,?,?,?,?,?, 'active')");
$insert->execute(['yt-user-1','UCaaaaaaaaaaaaaaaaaaaa','Chaîne 1','@chaine1','',p50yo_encrypt('access-one'),p50yo_encrypt('refresh-one'),'Bearer',implode(' ',P50YO_REQUIRED_SCOPES),gmdate('Y-m-d H:i:s',time()+3600)]);
$insert->execute(['yt-user-2','UCbbbbbbbbbbbbbbbbbbbb','Chaîne 2','@chaine2','',p50yo_encrypt('access-two'),p50yo_encrypt('refresh-two'),'Bearer',implode(' ',P50YO_REQUIRED_SCOPES),gmdate('Y-m-d H:i:s',time()+3600)]);

$first=p50ym_map_channel($pdo,'UCaaaaaaaaaaaaaaaaaaaa','profile-youtube-1','yt-user-1');
must($first['profileId']==='profile-youtube-1','La première chaîne doit être associée.');
must(p50ym_connection_for_profile($pdo,'profile-youtube-1')['channel_id']==='UCaaaaaaaaaaaaaaaaaaaa','La connexion associée doit être retrouvée.');
must(p50ym_public_access('profile-youtube-1')['authorized']===true,'Une chaîne associée doit être autorisée pour la collecte.');

p50ym_map_channel($pdo,'UCbbbbbbbbbbbbbbbbbbbb','profile-youtube-1','yt-user-2');
$mapped=$pdo->query("SELECT channel_id FROM p50_youtube_oauth_connections WHERE profile_id='profile-youtube-1'")->fetchAll(PDO::FETCH_COLUMN);
must($mapped===['UCbbbbbbbbbbbbbbbbbbbb'],'Une fiche ne doit conserver qu’une seule chaîne associée.');
must($pdo->query("SELECT profile_id FROM p50_youtube_oauth_connections WHERE channel_id='UCaaaaaaaaaaaaaaaaaaaa'")->fetchColumn()===null,'L’ancienne association doit être retirée automatiquement.');

$safe=p50ym_safe_connections($pdo);$encoded=json_encode($safe,JSON_UNESCAPED_SLASHES);
must($safe['schemaReady']===true,'Le schéma du pont doit être prêt.');
must($safe['summary']['mapped']===1&&$safe['summary']['unmapped']===1,'Le résumé des associations doit être exact.');
must(!str_contains($encoded,'yt-user-1')&&!str_contains($encoded,'yt-user-2'),'Les identifiants utilisateurs ne doivent pas être exposés.');
must(!str_contains($encoded,'access-one')&&!str_contains($encoded,'refresh-one'),'Les jetons ne doivent pas être exposés.');

p50ym_map_channel($pdo,'UCbbbbbbbbbbbbbbbbbbbb',null,'yt-user-2');
must(p50ym_connection_for_profile($pdo,'profile-youtube-1')===null,'Une association doit pouvoir être retirée.');
must(p50ym_index_exists($pdo,'uq_p50_youtube_oauth_profile'),'L’index unique de fiche doit exister.');

echo json_encode(['ok'=>true,'schemaIdempotent'=>true,'uniqueProfileMapping'=>true,'safeStatus'=>true,'mappingRemoval'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
