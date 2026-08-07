<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function ensure_fi_engagement_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS p50_fi_engagement (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        profile_id VARCHAR(100) NOT NULL,
        action_type ENUM('like','profile_share','live_share') NOT NULL,
        actor_hash CHAR(64) NULL,
        live_url VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_like (profile_id, action_type, actor_hash),
        KEY idx_profile_action (profile_id, action_type),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function engagement_actor_hash(): string {
    $cookie = $_COOKIE['p50_fi_actor'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $cookie)) {
        $cookie = bin2hex(random_bytes(16));
        setcookie('p50_fi_actor', $cookie, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return hash('sha256', $cookie . '|' . $ip . '|' . $ua);
}

ensure_fi_engagement_table();
require_method('GET', 'POST');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_input();
    $action = (string)($input['action'] ?? '');
    $profileId = trim((string)($input['profileId'] ?? ''));
    $liveUrl = trim((string)($input['liveUrl'] ?? ''));
    if (!in_array($action, ['like', 'unlike', 'profile_share', 'live_share'], true)) json_response(['error' => 'Action invalide.'], 422);
    if ($profileId === '' || !preg_match('/^[A-Za-z0-9_-]{1,100}$/', $profileId)) json_response(['error' => 'Profil invalide.'], 422);
    if ($action === 'live_share' && ($liveUrl === '' || !filter_var($liveUrl, FILTER_VALIDATE_URL))) json_response(['error' => 'Lien du live invalide.'], 422);

    if ($action === 'unlike') {
        $actorHash = engagement_actor_hash();
        try {
            $stmt = db()->prepare("DELETE FROM p50_fi_engagement WHERE profile_id=? AND action_type='like' AND actor_hash=?");
            $stmt->execute([$profileId, $actorHash]);
            json_response(['ok' => true, 'liked' => false, 'removed' => $stmt->rowCount() > 0]);
        } catch (PDOException $e) {
            error_log('FI engagement unlike: ' . $e->getMessage());
            json_response(['error' => 'Retrait impossible.'], 500);
        }
    }

    $actorHash = $action === 'like' ? engagement_actor_hash() : null;
    try {
        $stmt = db()->prepare('INSERT INTO p50_fi_engagement(profile_id,action_type,actor_hash,live_url,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())');
        $stmt->execute([$profileId, $action, $actorHash, $action === 'live_share' ? $liveUrl : null]);
        json_response(['ok' => true, 'created' => true, 'liked' => $action === 'like' ? true : null]);
    } catch (PDOException $e) {
        if ($action === 'like' && (string)$e->getCode() === '23000') json_response(['ok' => true, 'created' => false, 'duplicate' => true, 'liked' => true]);
        error_log('FI engagement: ' . $e->getMessage());
        json_response(['error' => 'Enregistrement impossible.'], 500);
    }
}

$user = auth_user(true);
require_role($user, 'owner', 'admin');
$sql = "SELECT profile_id,
SUM(action_type='like') likes,
SUM(action_type='profile_share') profile_shares,
SUM(action_type='live_share') live_shares
FROM p50_fi_engagement GROUP BY profile_id ORDER BY likes DESC, profile_shares DESC, live_shares DESC";
$rows = db()->query($sql)->fetchAll();
json_response(['profiles' => array_map(static fn(array $r): array => [
    'profileId' => $r['profile_id'],
    'likes' => (int)$r['likes'],
    'profileShares' => (int)$r['profile_shares'],
    'liveShares' => (int)$r['live_shares'],
], $rows)]);
