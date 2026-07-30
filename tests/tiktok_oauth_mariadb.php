<?php
declare(strict_types=1);

$dsn = getenv('P50_TEST_DSN') ?: '';
$user = getenv('P50_TEST_DB_USER') ?: '';
$password = getenv('P50_TEST_DB_PASSWORD') ?: '';
if ($dsn === '') throw new RuntimeException('P50_TEST_DSN absent.');

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['pdo'] = $pdo;
function db(): PDO { return $GLOBALS['pdo']; }

$pdo->exec('DROP TABLE IF EXISTS p50_tiktok_oauth_videos');
$pdo->exec('DROP TABLE IF EXISTS p50_tiktok_oauth_connections');
$pdo->exec('DROP TABLE IF EXISTS users');
$pdo->exec("CREATE TABLE users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("INSERT INTO users(id,email) VALUES('00000000-0000-0000-0000-000000000001','test@example.com')");

$config = [
    'tiktok_oauth' => [
        'client_key' => 'sandbox-client-key',
        'client_secret' => 'sandbox-client-secret',
        'redirect_uri' => 'https://www.pass50.store/api/tiktok-oauth-callback.php',
        'token_encryption_key' => str_repeat('k', 40),
        'environment' => 'sandbox',
    ],
];
require __DIR__ . '/../api/tiktok-oauth-core.php';
p50tk_ensure_schema();

$tables = ['p50_tiktok_oauth_connections', 'p50_tiktok_oauth_videos'];
foreach ($tables as $table) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException($table . ' absent');
}

$pdo->prepare("INSERT INTO p50_tiktok_oauth_connections
(user_id,open_id,access_token_encrypted,refresh_token_encrypted,scopes,access_expires_at,refresh_expires_at)
VALUES(?,?,?,?,?,?,?)")->execute([
    '00000000-0000-0000-0000-000000000001',
    'open-id-test',
    p50tk_encrypt('access'),
    p50tk_encrypt('refresh'),
    'user.info.basic video.list',
    gmdate('Y-m-d H:i:s', time() + 3600),
    gmdate('Y-m-d H:i:s', time() + 86400),
]);

$row = p50tk_connection_for_user('00000000-0000-0000-0000-000000000001');
if (!$row || p50tk_decrypt((string)$row['access_token_encrypted']) !== 'access') {
    throw new RuntimeException('connection storage failed');
}

$pdo->prepare("INSERT INTO p50_tiktok_oauth_videos(user_id,video_id,title) VALUES(?,?,?)")
    ->execute(['00000000-0000-0000-0000-000000000001','video-1','Test']);
if (count(p50tk_videos_for_user('00000000-0000-0000-0000-000000000001')) !== 1) {
    throw new RuntimeException('video storage failed');
}

echo "TikTok OAuth MariaDB: OK\n";
