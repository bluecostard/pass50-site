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
$pdo->exec("INSERT INTO users(id,email) VALUES
('00000000-0000-0000-0000-000000000001','test1@example.com'),
('00000000-0000-0000-0000-000000000002','test2@example.com')");

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
require __DIR__ . '/../api/tiktok-oauth-store-v2.php';
p50tk_ensure_schema();

$tables = ['p50_tiktok_oauth_connections', 'p50_tiktok_oauth_videos'];
foreach ($tables as $table) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException($table . ' absent');
}

$tokens = [
    'access_token' => 'access-v1',
    'refresh_token' => 'refresh-v1',
    'token_type' => 'Bearer',
    'scope' => 'user.info.basic,user.info.profile,user.info.stats,video.list',
    'expires_in' => 3600,
    'refresh_expires_in' => 86400,
];
$profile = [
    'open_id' => 'open-id-test',
    'union_id' => 'union-id-test',
    'display_name' => 'Compte test',
    'username' => 'compte.test',
    'follower_count' => 123,
    'following_count' => 12,
    'likes_count' => 456,
    'video_count' => 1,
];
$videos = [[
    'id' => 'video-1',
    'title' => 'Test',
    'create_time' => time() - 60,
    'view_count' => 100,
    'like_count' => 10,
    'comment_count' => 2,
    'share_count' => 1,
]];

p50tk_store_snapshot_v2('00000000-0000-0000-0000-000000000001', $tokens, $profile, $videos);
$row = p50tk_connection_for_user('00000000-0000-0000-0000-000000000001');
if (!$row || p50tk_decrypt((string)$row['access_token_encrypted']) !== 'access-v1') {
    throw new RuntimeException('connection storage failed');
}
if ((int)$row['follower_count'] !== 123) throw new RuntimeException('profile statistics missing');
if (count(p50tk_videos_for_user('00000000-0000-0000-0000-000000000001')) !== 1) {
    throw new RuntimeException('video storage failed');
}

$tokens['access_token'] = 'access-v2';
$tokens['refresh_token'] = 'refresh-v2';
$profile['display_name'] = 'Compte actualisé';
p50tk_store_snapshot_v2('00000000-0000-0000-0000-000000000001', $tokens, $profile, []);
$row = p50tk_connection_for_user('00000000-0000-0000-0000-000000000001');
if (!$row || (string)$row['display_name'] !== 'Compte actualisé') throw new RuntimeException('connection update failed');
if (p50tk_decrypt((string)$row['access_token_encrypted']) !== 'access-v2') throw new RuntimeException('token update failed');
if (count(p50tk_videos_for_user('00000000-0000-0000-0000-000000000001')) !== 0) {
    throw new RuntimeException('stale videos not removed');
}

$collisionRejected = false;
try {
    p50tk_store_snapshot_v2('00000000-0000-0000-0000-000000000002', $tokens, $profile, []);
} catch (RuntimeException $e) {
    $collisionRejected = str_contains($e->getMessage(), 'déjà lié');
}
if (!$collisionRejected) throw new RuntimeException('duplicate TikTok identity accepted');
if (p50tk_connection_for_user('00000000-0000-0000-0000-000000000002')) {
    throw new RuntimeException('second PASS50 account was linked');
}

echo "TikTok OAuth MariaDB: OK\n";
