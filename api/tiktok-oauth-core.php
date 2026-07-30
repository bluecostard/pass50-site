<?php
declare(strict_types=1);

const P50TK_REQUIRED_SCOPES = [
    'user.info.basic',
    'user.info.profile',
    'user.info.stats',
    'video.list',
];

function p50tk_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $path = dirname(__DIR__) . '/migration-tiktok-oauth-v1.sql';
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Migration OAuth TikTok introuvable.');
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') db()->exec($statement);
    }
}

function p50tk_config_value(array $oauth, string $key, string $environmentName): string {
    $configured = trim((string)($oauth[$key] ?? ''));
    if ($configured !== '') return $configured;
    return trim((string)(getenv($environmentName) ?: ''));
}

function p50tk_config(): array {
    global $config;
    $oauth = is_array($config['tiktok_oauth'] ?? null) ? $config['tiktok_oauth'] : [];
    $values = [
        'client_key' => p50tk_config_value($oauth, 'client_key', 'TIKTOK_CLIENT_KEY'),
        'client_secret' => p50tk_config_value($oauth, 'client_secret', 'TIKTOK_CLIENT_SECRET'),
        'redirect_uri' => p50tk_config_value($oauth, 'redirect_uri', 'TIKTOK_REDIRECT_URI'),
        'token_encryption_key' => p50tk_config_value($oauth, 'token_encryption_key', 'PASS50_TOKEN_ENCRYPTION_KEY'),
        'environment' => strtolower(p50tk_config_value($oauth, 'environment', 'TIKTOK_ENVIRONMENT') ?: 'sandbox'),
    ];
    if ($values['client_key'] === '' || $values['client_secret'] === '' || $values['redirect_uri'] === '') {
        throw new RuntimeException('Configuration OAuth TikTok incomplète dans api/config.php.');
    }
    if (!filter_var($values['redirect_uri'], FILTER_VALIDATE_URL) || !str_starts_with($values['redirect_uri'], 'https://')) {
        throw new RuntimeException('URI de redirection OAuth TikTok invalide.');
    }
    if ($values['token_encryption_key'] === '') {
        throw new RuntimeException('Clé de chiffrement OAuth manquante dans api/config.php.');
    }
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('Extension OpenSSL indisponible sur le serveur.');
    }
    return $values;
}

function p50tk_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function p50tk_base64url_decode(string $value): string {
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
    if ($decoded === false) throw new RuntimeException('Valeur chiffrée invalide.');
    return $decoded;
}

function p50tk_encryption_key(): string {
    $raw = p50tk_config()['token_encryption_key'];
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && strlen($decoded) === 32) return $decoded;
    if (strlen($raw) >= 32) return hash('sha256', $raw, true);
    throw new RuntimeException('La clé de chiffrement OAuth doit contenir au moins 32 caractères ou 32 octets encodés en base64.');
}

function p50tk_encrypt(string $plaintext): string {
    if ($plaintext === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        p50tk_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'PASS50:tiktok-oauth:v1',
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Chiffrement du jeton TikTok impossible.');
    }
    return 'v1.' . p50tk_base64url_encode($iv) . '.' . p50tk_base64url_encode($tag) . '.' . p50tk_base64url_encode($ciphertext);
}

function p50tk_decrypt(?string $payload): string {
    if ($payload === null || $payload === '') return '';
    $parts = explode('.', $payload);
    if (count($parts) !== 4 || $parts[0] !== 'v1') throw new RuntimeException('Format de jeton TikTok inconnu.');
    $plaintext = openssl_decrypt(
        p50tk_base64url_decode($parts[3]),
        'aes-256-gcm',
        p50tk_encryption_key(),
        OPENSSL_RAW_DATA,
        p50tk_base64url_decode($parts[1]),
        p50tk_base64url_decode($parts[2]),
        'PASS50:tiktok-oauth:v1'
    );
    if ($plaintext === false) throw new RuntimeException('Déchiffrement du jeton TikTok impossible.');
    return $plaintext;
}

function p50tk_http(
    string $url,
    string $method = 'GET',
    array $headers = [],
    ?array $form = null,
    ?array $json = null
): array {
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('cURL indisponible.');
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'PASS50-TikTok-OAuth/1.0',
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        if ($json !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            $options[CURLOPT_POSTFIELDS] = http_build_query($form ?? [], '', '&', PHP_QUERY_RFC3986);
        }
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Erreur réseau OAuth TikTok : ' . $error);
    $decoded = json_decode((string)$body, true);
    return [
        'status' => $status,
        'body' => (string)$body,
        'json' => is_array($decoded) ? $decoded : [],
    ];
}

function p50tk_api_error(array $response, string $fallback): RuntimeException {
    $data = is_array($response['json'] ?? null) ? $response['json'] : [];
    $message = trim((string)($data['error_description'] ?? $data['message'] ?? ''));
    $error = $data['error'] ?? null;
    if ($message === '' && is_array($error)) $message = trim((string)($error['message'] ?? $error['code'] ?? ''));
    if ($message === '' && is_string($error)) $message = trim($error);
    return new RuntimeException($message !== '' ? $fallback . ' : ' . $message : $fallback . ' (HTTP ' . (int)($response['status'] ?? 0) . ').');
}

function p50tk_utc_timestamp(?string $value): ?int {
    if ($value === null || trim($value) === '') return null;
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

function p50tk_connection_for_user(string $userId): ?array {
    $stmt = db()->prepare('SELECT * FROM p50_tiktok_oauth_connections WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function p50tk_videos_for_user(string $userId, int $limit = 10): array {
    $limit = max(1, min(20, $limit));
    $stmt = db()->prepare(
        'SELECT video_id,title,video_description,cover_image_url,share_url,embed_link,duration_seconds,published_at,view_count,like_count,comment_count,share_count,fetched_at '
        . 'FROM p50_tiktok_oauth_videos WHERE user_id=? ORDER BY published_at DESC,video_id DESC LIMIT ' . $limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function p50tk_fetch_profile(string $accessToken): array {
    $fields = implode(',', [
        'open_id','union_id','avatar_url','avatar_large_url','display_name','bio_description',
        'profile_deep_link','is_verified','username','follower_count','following_count','likes_count','video_count'
    ]);
    $response = p50tk_http(
        'https://open.tiktokapis.com/v2/user/info/?' . http_build_query(['fields' => $fields], '', '&', PHP_QUERY_RFC3986),
        'GET',
        ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
    );
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw p50tk_api_error($response, 'Lecture du profil TikTok impossible');
    }
    $user = $response['json']['data']['user'] ?? null;
    if (!is_array($user) || trim((string)($user['open_id'] ?? '')) === '') {
        throw new RuntimeException('TikTok n’a retourné aucun profil accessible.');
    }
    return $user;
}

function p50tk_fetch_videos(string $accessToken, int $limit = 10): array {
    $limit = max(1, min(20, $limit));
    $fields = implode(',', [
        'id','title','video_description','duration','cover_image_url','embed_link',
        'share_url','create_time','view_count','like_count','comment_count','share_count'
    ]);
    $response = p50tk_http(
        'https://open.tiktokapis.com/v2/video/list/?' . http_build_query(['fields' => $fields], '', '&', PHP_QUERY_RFC3986),
        'POST',
        ['Authorization: Bearer ' . $accessToken, 'Accept: application/json', 'Content-Type: application/json'],
        null,
        ['max_count' => $limit]
    );
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw p50tk_api_error($response, 'Lecture des vidéos TikTok impossible');
    }
    $videos = $response['json']['data']['videos'] ?? [];
    return is_array($videos) ? array_values(array_filter($videos, 'is_array')) : [];
}

function p50tk_store_snapshot(
    string $userId,
    array $tokens,
    array $profile,
    array $videos
): void {
    p50tk_ensure_schema();
    $accessExpiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int)($tokens['expires_in'] ?? 86400)));
    $refreshExpiresAt = gmdate('Y-m-d H:i:s', time() + max(3600, (int)($tokens['refresh_expires_in'] ?? 31536000)));
    $scopes = str_replace(',', ' ', trim((string)($tokens['scope'] ?? '')));
    $db = db();
    $existingOwner = $db->prepare('SELECT user_id FROM p50_tiktok_oauth_connections WHERE open_id=? AND user_id<>? LIMIT 1');
    $existingOwner->execute([(string)$profile['open_id'], $userId]);
    if ($existingOwner->fetchColumn()) {
        throw new RuntimeException('Ce compte TikTok est déjà lié à un autre compte PASS50.');
    }
    $db->beginTransaction();
    try {
        $sql = "INSERT INTO p50_tiktok_oauth_connections
            (user_id,open_id,union_id,display_name,username,avatar_url,profile_deep_link,bio_description,is_verified,
             follower_count,following_count,likes_count,video_count,access_token_encrypted,refresh_token_encrypted,
             token_type,scopes,access_expires_at,refresh_expires_at,status,last_error,connected_at,last_refreshed_at,last_synced_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
              open_id=VALUES(open_id),union_id=VALUES(union_id),display_name=VALUES(display_name),username=VALUES(username),
              avatar_url=VALUES(avatar_url),profile_deep_link=VALUES(profile_deep_link),bio_description=VALUES(bio_description),
              is_verified=VALUES(is_verified),follower_count=VALUES(follower_count),following_count=VALUES(following_count),
              likes_count=VALUES(likes_count),video_count=VALUES(video_count),access_token_encrypted=VALUES(access_token_encrypted),
              refresh_token_encrypted=VALUES(refresh_token_encrypted),token_type=VALUES(token_type),scopes=VALUES(scopes),
              access_expires_at=VALUES(access_expires_at),refresh_expires_at=VALUES(refresh_expires_at),status='active',
              last_error=NULL,connected_at=UTC_TIMESTAMP(),last_refreshed_at=UTC_TIMESTAMP(),last_synced_at=UTC_TIMESTAMP()";
        $db->prepare($sql)->execute([
            $userId,
            (string)$profile['open_id'],
            (string)($profile['union_id'] ?? ''),
            (string)($profile['display_name'] ?? ''),
            (string)($profile['username'] ?? ''),
            (string)($profile['avatar_large_url'] ?? $profile['avatar_url'] ?? ''),
            (string)($profile['profile_deep_link'] ?? ''),
            (string)($profile['bio_description'] ?? ''),
            !empty($profile['is_verified']) ? 1 : 0,
            isset($profile['follower_count']) ? (int)$profile['follower_count'] : null,
            isset($profile['following_count']) ? (int)$profile['following_count'] : null,
            isset($profile['likes_count']) ? (int)$profile['likes_count'] : null,
            isset($profile['video_count']) ? (int)$profile['video_count'] : null,
            p50tk_encrypt((string)$tokens['access_token']),
            p50tk_encrypt((string)$tokens['refresh_token']),
            (string)($tokens['token_type'] ?? 'Bearer'),
            $scopes,
            $accessExpiresAt,
            $refreshExpiresAt,
        ]);
        $db->prepare('DELETE FROM p50_tiktok_oauth_videos WHERE user_id=?')->execute([$userId]);
        $videoSql = "INSERT INTO p50_tiktok_oauth_videos
            (user_id,video_id,title,video_description,cover_image_url,share_url,embed_link,duration_seconds,published_at,
             view_count,like_count,comment_count,share_count,fetched_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())";
        $videoStmt = $db->prepare($videoSql);
        foreach ($videos as $video) {
            $videoId = trim((string)($video['id'] ?? ''));
            if ($videoId === '') continue;
            $created = isset($video['create_time']) && is_numeric($video['create_time'])
                ? gmdate('Y-m-d H:i:s', (int)$video['create_time'])
                : null;
            $videoStmt->execute([
                $userId,
                $videoId,
                (string)($video['title'] ?? ''),
                (string)($video['video_description'] ?? ''),
                (string)($video['cover_image_url'] ?? ''),
                (string)($video['share_url'] ?? ''),
                (string)($video['embed_link'] ?? ''),
                isset($video['duration']) ? (int)$video['duration'] : null,
                $created,
                isset($video['view_count']) ? (int)$video['view_count'] : null,
                isset($video['like_count']) ? (int)$video['like_count'] : null,
                isset($video['comment_count']) ? (int)$video['comment_count'] : null,
                isset($video['share_count']) ? (int)$video['share_count'] : null,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function p50tk_refresh_access_token(string $userId): string {
    $connection = p50tk_connection_for_user($userId);
    if (!$connection) throw new RuntimeException('Aucun compte TikTok connecté.');
    $currentToken = p50tk_decrypt((string)$connection['access_token_encrypted']);
    $expiresAt = p50tk_utc_timestamp((string)$connection['access_expires_at']);
    if ($currentToken !== '' && $expiresAt !== null && $expiresAt > time() + 120) return $currentToken;
    $refreshToken = p50tk_decrypt((string)$connection['refresh_token_encrypted']);
    if ($refreshToken === '') throw new RuntimeException('Le compte TikTok doit être reconnecté.');
    $oauth = p50tk_config();
    $response = p50tk_http(
        'https://open.tiktokapis.com/v2/oauth/token/',
        'POST',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        [
            'client_key' => $oauth['client_key'],
            'client_secret' => $oauth['client_secret'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]
    );
    if ($response['status'] < 200 || $response['status'] >= 300) {
        db()->prepare("UPDATE p50_tiktok_oauth_connections SET status='reauthorization_required',last_error='refresh_failed' WHERE user_id=?")
            ->execute([$userId]);
        throw p50tk_api_error($response, 'Actualisation du jeton TikTok refusée');
    }
    $tokens = $response['json'];
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    $newRefresh = trim((string)($tokens['refresh_token'] ?? ''));
    if ($accessToken === '' || $newRefresh === '') throw new RuntimeException('TikTok n’a pas retourné les nouveaux jetons attendus.');
    db()->prepare(
        "UPDATE p50_tiktok_oauth_connections SET access_token_encrypted=?,refresh_token_encrypted=?,token_type=?,scopes=?,
         access_expires_at=?,refresh_expires_at=?,status='active',last_error=NULL,last_refreshed_at=UTC_TIMESTAMP() WHERE user_id=?"
    )->execute([
        p50tk_encrypt($accessToken),
        p50tk_encrypt($newRefresh),
        (string)($tokens['token_type'] ?? 'Bearer'),
        str_replace(',', ' ', trim((string)($tokens['scope'] ?? ''))),
        gmdate('Y-m-d H:i:s', time() + max(60, (int)($tokens['expires_in'] ?? 86400))),
        gmdate('Y-m-d H:i:s', time() + max(3600, (int)($tokens['refresh_expires_in'] ?? 31536000))),
        $userId,
    ]);
    return $accessToken;
}

function p50tk_redirect_result(string $status, string $code = ''): never {
    $query = ['tiktok_oauth' => $status];
    if ($code !== '') $query['code'] = $code;
    $target = '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PASS50 · TikTok</title></head><body>';
    echo '<p>Connexion TikTok terminée. Redirection vers PASS50…</p>';
    echo '<script>window.location.replace(' . json_encode($target, JSON_UNESCAPED_SLASHES) . ');</script>';
    echo '</body></html>';
    exit;
}
