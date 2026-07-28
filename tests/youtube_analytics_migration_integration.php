<?php
declare(strict_types=1);

$dsn = getenv('P50_TEST_DSN') ?: '';
$user = getenv('P50_TEST_DB_USER') ?: '';
$password = getenv('P50_TEST_DB_PASSWORD') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "P50_TEST_DSN manquant.\n");
    exit(1);
}

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  email VARCHAR(255) NOT NULL DEFAULT '',
  deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$sql = file_get_contents(dirname(__DIR__) . '/migration-youtube-analytics-v1.sql');
if ($sql === false) throw new RuntimeException('Migration introuvable.');
$statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
foreach ([1, 2] as $_) {
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $pdo->exec($statement);
    }
}

$table = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_youtube_analytics_snapshots'")->fetchColumn();
if ((int)$table !== 1) throw new RuntimeException('Table YouTube Analytics absente.');
$columns = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_youtube_analytics_snapshots'")->fetchColumn();
if ($columns < 18) throw new RuntimeException('Schéma YouTube Analytics incomplet.');

$userId = '00000000-0000-4000-8000-000000000001';
$pdo->prepare('INSERT IGNORE INTO users(id,email) VALUES(?,?)')->execute([$userId, 'analytics@example.test']);
$key = hash('sha256', 'youtube-analytics-migration-test');
$stmt = $pdo->prepare("INSERT IGNORE INTO p50_youtube_analytics_snapshots
  (snapshot_key,user_id,channel_id,period_days,start_date,end_date,has_data,views,raw_payload_hash)
  VALUES(?,?,?,?,?,?,?,?,?)");
$stmt->execute([$key, $userId, 'UC_TEST', 28, '2026-07-01', '2026-07-28', 1, 42, hash('sha256', 'payload')]);
$count = $pdo->prepare('SELECT COUNT(*) FROM p50_youtube_analytics_snapshots WHERE snapshot_key=?');
$count->execute([$key]);
if ((int)$count->fetchColumn() !== 1) throw new RuntimeException('Insertion YouTube Analytics impossible.');

echo "YouTube Analytics migration: OK\n";
