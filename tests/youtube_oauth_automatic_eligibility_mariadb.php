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
        'redirect_uri'=>'https://www.pass50.store/api/youtube-oauth-callback.php','token_encryption_key'=>base64_encode(str_repeat('z',32)),
    ],
    'data_engine'=>['confidence_threshold'=>80,'live_stale_minutes'=>45],
    'metrics'=>['orchestrator_enabled'=>true,'cron_secret'=>hash('sha256','youtube-oauth-auto-test')],
];

foreach(['p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_youtube_analytics_snapshots','p50_youtube_oauth_states','p50_youtube_oauth_connections','p50_ranking_snapshots','p50_live_streams','p50_social_links','p50_profile_registry','users'] as $table)$pdo->exec("SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS `$table`; SET FOREIGN_KEY_CHECKS=1");
$pdo->exec("CREATE TABLE users (id CHAR(36) PRIMARY KEY,email VARCHAR(190) NOT NULL DEFAULT '',display_name VARCHAR(190) NOT NULL DEFAULT '',role VARCHAR(24) NOT NULL DEFAULT 'user',email_confirmed_at DATETIME NULL,deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE p50_profile_registry (profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190) NOT NULL,alive TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci");
$pdo->exec("CREATE TABLE p50_social_links (profile_id VARCHAR(100),platform VARCHAR(32),normalized_url TEXT,confidence INT,status VARCHAR(24),PRIMARY KEY(profile_id,platform)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE p50_live_streams (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile_id VARCHAR(100),status VARCHAR(24),last_seen_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

require dirname(__DIR__).'/api/metrics-orchestrator-core.php';

$pdo->prepare('INSERT INTO users(id,email,display_name,role) VALUES(?,?,?,?)')->execute(['yt-auto-user','auto@example.test','Auto','owner']);
$pdo->prepare('INSERT INTO p50_profile_registry(profile_id,public_name,alive) VALUES(?,?,1)')->execute(['yt-auto-profile','Profil OAuth automatique']);
p50ym_ensure_schema($pdo);
p50_metrics_ensure_schema($pdo);

$pdo->prepare("INSERT INTO p50_youtube_oauth_connections(user_id,channel_id,channel_title,channel_custom_url,channel_thumbnail_url,access_token_encrypted,refresh_token_encrypted,token_type,scopes,access_expires_at,status) VALUES(?,?,?,?,?,?,?,?,?,?, 'active')")
    ->execute(['yt-auto-user','UCautomatic1234567890','Chaîne automatique','@chaine-auto','',p50yo_encrypt('access-auto'),p50yo_encrypt('refresh-auto'),'Bearer',implode(' ',P50YO_REQUIRED_SCOPES),gmdate('Y-m-d H:i:s',time()+3600)]);

p50ym_map_channel($pdo,'UCautomatic1234567890','yt-auto-profile','yt-auto-user');
must((int)$pdo->query("SELECT COUNT(*) FROM p50_social_links WHERE profile_id='yt-auto-profile' AND platform='YouTube'")->fetchColumn()===0,'Le test ne doit contenir aucun lien YouTube manuel.');

$official=p50_mc_official($pdo,'yt-auto-profile','YouTube');
must($official['profile_id']==='yt-auto-profile','La fiche OAuth doit fournir une source officielle synthétique.');
must($official['normalized_url']==='https://www.youtube.com/channel/UCautomatic1234567890','La source officielle doit utiliser l’identifiant stable de chaîne.');
must((int)$official['confidence']===99,'La confiance OAuth doit être maximale sans être absolue.');

$selection=p50_mo_select($pdo,'p2');
$candidates=array_values(array_filter($selection['candidates'],static fn(array $row): bool=>$row['profileId']==='yt-auto-profile'&&$row['platform']==='YouTube'));
must(count($candidates)===1,'La chaîne OAuth associée doit entrer dans le recensement sans lien manuel.');

$queued=p50_mo_enqueue_profile($pdo,'yt-auto-profile','YouTube','p0',['reason'=>'oauth_mapping','priorityOverride'=>5]);
must($queued['created']===true,'Une première tâche prioritaire doit être créée.');
must((int)$pdo->query("SELECT COUNT(*) FROM p50_metric_jobs WHERE scope_id='yt-auto-profile' AND platform='YouTube' AND priority=5 AND status='pending'")->fetchColumn()===1,'La tâche prioritaire doit être persistée.');
$duplicate=p50_mo_enqueue_profile($pdo,'yt-auto-profile','YouTube','p0',['reason'=>'oauth_mapping','priorityOverride'=>5]);
must($duplicate['created']===false&&$duplicate['duplicate']===true,'La planification immédiate doit être idempotente dans le même bucket.');

echo json_encode(['ok'=>true,'officialWithoutManualLink'=>true,'scheduledAutomatically'=>true,'idempotent'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
