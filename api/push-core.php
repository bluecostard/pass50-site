<?php
declare(strict_types=1);

/**
 * PASS50 Push V1 — enregistrement appareils iOS (APNs via Capacitor).
 */
const P50_PUSH_VERSION = 'PUSH-V1.0';

function p50_push_config(): array {
    global $config;
    $p = (array)($config['push'] ?? []);
    return [
        'enabled' => filter_var($p['enabled'] ?? (getenv('PASS50_PUSH_ENABLED') ?: 'false'), FILTER_VALIDATE_BOOLEAN),
        'apnsKeyId' => (string)($p['apns_key_id'] ?? getenv('PASS50_APNS_KEY_ID') ?: ''),
        'apnsTeamId' => (string)($p['apns_team_id'] ?? getenv('PASS50_APNS_TEAM_ID') ?: ''),
        'apnsBundleId' => (string)($p['apns_bundle_id'] ?? getenv('PASS50_APNS_BUNDLE_ID') ?: 'store.pass50.app'),
        'apnsKeyPath' => (string)($p['apns_key_path'] ?? getenv('PASS50_APNS_KEY_PATH') ?: ''),
        'apnsProduction' => filter_var($p['apns_production'] ?? (getenv('PASS50_APNS_PRODUCTION') ?: 'false'), FILTER_VALIDATE_BOOLEAN),
        'cronSecret' => (string)($p['cron_secret'] ?? getenv('PASS50_PUSH_CRON_SECRET') ?: ($config['metrics']['cron_secret'] ?? '')),
    ];
}

function p50_push_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_push_devices (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      device_id VARCHAR(80) CHARACTER SET ascii NOT NULL,
      platform VARCHAR(16) NOT NULL DEFAULT 'ios',
      push_token VARCHAR(255) CHARACTER SET ascii NOT NULL,
      user_id CHAR(36) CHARACTER SET ascii NULL,
      app_version VARCHAR(32) NOT NULL DEFAULT '',
      locale VARCHAR(16) NOT NULL DEFAULT 'fr',
      topics_json LONGTEXT NOT NULL,
      last_seen_at DATETIME NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_p50_push_device(device_id),
      UNIQUE KEY uq_p50_push_token(push_token),
      INDEX idx_p50_push_user(user_id),
      INDEX idx_p50_push_seen(last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_push_normalize_topics(mixed $topics): array {
    $allowed = ['lives' => true, 'ranking' => true, 'coules' => true];
    $out = [];
    if (!is_array($topics)) {
        return ['lives' => true, 'ranking' => true, 'coules' => false];
    }
    foreach ($allowed as $key => $_) {
        $out[$key] = !empty($topics[$key]);
    }
    return $out;
}

function p50_push_register(PDO $pdo, array $input, ?array $user): array {
    p50_push_ensure_schema($pdo);
    $deviceId = trim((string)($input['deviceId'] ?? ''));
    $token = trim((string)($input['token'] ?? ''));
    $platform = strtolower(trim((string)($input['platform'] ?? 'ios')));
    if ($platform !== 'ios') {
        throw new InvalidArgumentException('Plateforme non supportée en V1 (ios uniquement).');
    }
    if ($deviceId === '' || strlen($deviceId) > 80 || !preg_match('/^[A-Za-z0-9._:-]+$/', $deviceId)) {
        throw new InvalidArgumentException('deviceId invalide.');
    }
    if ($token === '' || strlen($token) > 255 || !preg_match('/^[A-Za-z0-9._:-]+$/', $token)) {
        throw new InvalidArgumentException('token push invalide.');
    }
    $topics = p50_push_normalize_topics($input['topics'] ?? null);
    $appVersion = mb_substr(trim((string)($input['appVersion'] ?? '')), 0, 32);
    $locale = mb_substr(trim((string)($input['locale'] ?? 'fr')), 0, 16) ?: 'fr';
    $userId = $user['id'] ?? null;
    $now = gmdate('Y-m-d H:i:s');
    $topicsJson = json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $stmt = $pdo->prepare("INSERT INTO p50_push_devices
      (device_id, platform, push_token, user_id, app_version, locale, topics_json, last_seen_at)
      VALUES (?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        platform=VALUES(platform),
        push_token=VALUES(push_token),
        user_id=COALESCE(VALUES(user_id), user_id),
        app_version=VALUES(app_version),
        locale=VALUES(locale),
        topics_json=VALUES(topics_json),
        last_seen_at=VALUES(last_seen_at)");
    $stmt->execute([$deviceId, $platform, $token, $userId, $appVersion, $locale, $topicsJson, $now]);

    return [
        'ok' => true,
        'version' => P50_PUSH_VERSION,
        'deviceId' => $deviceId,
        'platform' => $platform,
        'topics' => $topics,
        'userLinked' => $userId !== null,
    ];
}

function p50_push_unregister(PDO $pdo, string $deviceId): array {
    p50_push_ensure_schema($pdo);
    $deviceId = trim($deviceId);
    if ($deviceId === '') {
        throw new InvalidArgumentException('deviceId requis.');
    }
    $stmt = $pdo->prepare('DELETE FROM p50_push_devices WHERE device_id=?');
    $stmt->execute([$deviceId]);
    return ['ok' => true, 'removed' => $stmt->rowCount() > 0];
}

function p50_push_apns_jwt(array $cfg): string {
    $keyPath = $cfg['apnsKeyPath'];
    if ($keyPath === '' || !is_readable($keyPath)) {
        throw new RuntimeException('Clé APNs .p8 introuvable.');
    }
    if ($cfg['apnsKeyId'] === '' || $cfg['apnsTeamId'] === '') {
        throw new RuntimeException('APNs keyId / teamId manquants.');
    }
    $privateKey = openssl_pkey_get_private((string)file_get_contents($keyPath));
    if (!$privateKey) {
        throw new RuntimeException('Clé APNs illisible.');
    }
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'kid' => $cfg['apnsKeyId']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $claims = rtrim(strtr(base64_encode(json_encode(['iss' => $cfg['apnsTeamId'], 'iat' => time()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $unsigned = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Signature APNs impossible.');
    }
    // ES256 raw R||S (64 bytes) expected by APNs after DER conversion:
    $signature = p50_push_der_to_jose($signature);
    return $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}

/** Convertit une signature ECDSA DER OpenSSL en R||S (64 octets) pour JWT ES256. */
function p50_push_der_to_jose(string $der): string {
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) {
        throw new RuntimeException('Signature DER invalide.');
    }
    $len = ord($der[$offset++]);
    if ($len & 0x80) {
        $offset += ($len & 0x7f);
    }
    if (ord($der[$offset++]) !== 0x02) {
        throw new RuntimeException('Signature DER invalide (R).');
    }
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;
    if (ord($der[$offset++]) !== 0x02) {
        throw new RuntimeException('Signature DER invalide (S).');
    }
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

function p50_push_send_apns(array $cfg, string $deviceToken, string $title, string $body, array $data = []): array {
    if (!$cfg['enabled']) {
        throw new RuntimeException('Push désactivé.');
    }
    $jwt = p50_push_apns_jwt($cfg);
    $host = $cfg['apnsProduction'] ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
    $url = $host . '/3/device/' . $deviceToken;
    $payload = json_encode([
        'aps' => [
            'alert' => ['title' => $title, 'body' => $body],
            'sound' => 'default',
        ],
        'pass50' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        CURLOPT_HTTPHEADER => [
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . $cfg['apnsBundleId'],
            'apns-push-type: alert',
            'apns-priority: 10',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $errno) {
        throw new RuntimeException('APNs curl: ' . ($error ?: 'échec'));
    }
    return ['ok' => $status === 200, 'status' => $status];
}

function p50_push_broadcast(PDO $pdo, string $topic, string $title, string $body, array $data = [], int $limit = 200): array {
    $cfg = p50_push_config();
    p50_push_ensure_schema($pdo);
    if (!in_array($topic, ['lives', 'ranking', 'coules'], true)) {
        throw new InvalidArgumentException('topic invalide.');
    }
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->query("SELECT device_id, push_token, topics_json FROM p50_push_devices WHERE platform='ios' ORDER BY last_seen_at DESC LIMIT " . (int)$limit);
    $sent = 0;
    $failed = 0;
    $skipped = 0;
    foreach ($stmt->fetchAll() as $row) {
        $topics = json_decode((string)$row['topics_json'], true) ?: [];
        if (empty($topics[$topic])) {
            $skipped++;
            continue;
        }
        try {
            $result = p50_push_send_apns($cfg, (string)$row['push_token'], $title, $body, $data + ['topic' => $topic]);
            if (!empty($result['ok'])) {
                $sent++;
            } else {
                $failed++;
            }
        } catch (Throwable $e) {
            error_log('PASS50 push send: ' . $e->getMessage());
            $failed++;
        }
    }
    return [
        'ok' => true,
        'version' => P50_PUSH_VERSION,
        'topic' => $topic,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
    ];
}
