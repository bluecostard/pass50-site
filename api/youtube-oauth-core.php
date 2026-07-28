<?php
declare(strict_types=1);

const P50YO_REQUIRED_SCOPES = [
    'https://www.googleapis.com/auth/youtube.readonly',
    'https://www.googleapis.com/auth/yt-analytics.readonly',
];

function p50yo_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $path = dirname(__DIR__) . '/migration-youtube-oauth-v1.sql';
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Migration OAuth YouTube introuvable.');

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') db()->exec($statement);
    }
}

function p50yo_config_value(array $oauth, string $key, string $environmentName): string {
    $configured = trim((string)($oauth[$key] ?? ''));
    if ($configured !== '') return $configured;
    return trim((string)(getenv($environmentName) ?: ''));
}

function p50yo_config(): array {
    global $config;
    $oauth = is_array($config['google_oauth'] ?? null) ? $config['google_oauth'] : [];

    $values = [
        'client_id' => p50yo_config_value($oauth, 'client_id', 'GOOGLE_CLIENT_ID'),
        'client_secret' => p50yo_config_value($oauth, 'client_secret', 'GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => p50yo_config_value($oauth, 'redirect_uri', 'GOOGLE_REDIRECT_URI'),
        'token_encryption_key' => p50yo_config_value($oauth, 'token_encryption_key', 'PASS50_TOKEN_ENCRYPTION_KEY'),
    ];

    if ($values['client_id'] === '' || $values['client_secret'] === '' || $values['redirect_uri'] === '') {
        throw new RuntimeException('Configuration OAuth Google incomplète dans api/config.php.');
    }
    if (!filter_var($values['redirect_uri'], FILTER_VALIDATE_URL) || !str_starts_with($values['redirect_uri'], 'https://')) {
        throw new RuntimeException('URI de redirection OAuth Google invalide.');
    }
    if ($values['token_encryption_key'] === '') {
        throw new RuntimeException('Clé de chiffrement OAuth manquante dans api/config.php.');
    }
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('Extension OpenSSL indisponible sur le serveur.');
    }

    return $values;
}

function p50yo_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function p50yo_base64url_decode(string $value): string {
    $padding = (4 - strlen($value) % 4) % 4;
    $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
    if ($decoded === false) throw new RuntimeException('Valeur chiffrée invalide.');
    return $decoded;
}

function p50yo_encryption_key(): string {
    $raw = p50yo_config()['token_encryption_key'];
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && strlen($decoded) === 32) return $decoded;
    if (strlen($raw) >= 32) return hash('sha256', $raw, true);
    throw new RuntimeException('La clé de chiffrement OAuth doit contenir au moins 32 caractères ou 32 octets encodés en base64.');
}

function p50yo_encrypt(string $plaintext): string {
    if ($plaintext === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        p50yo_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'PASS50:youtube-oauth:v1',
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Chiffrement du jeton OAuth impossible.');
    }
    return 'v1.' . p50yo_base64url_encode($iv) . '.' . p50yo_base64url_encode($tag) . '.' . p50yo_base64url_encode($ciphertext);
}

function p50yo_decrypt(?string $payload): string {
    if ($payload === null || $payload === '') return '';
    $parts = explode('.', $payload);
    if (count($parts) !== 4 || $parts[0] !== 'v1') throw new RuntimeException('Format de jeton OAuth inconnu.');

    $plaintext = openssl_decrypt(
        p50yo_base64url_decode($parts[3]),
        'aes-256-gcm',
        p50yo_encryption_key(),
        OPENSSL_RAW_DATA,
        p50yo_base64url_decode($parts[1]),
        p50yo_base64url_decode($parts[2]),
        'PASS50:youtube-oauth:v1'
    );
    if ($plaintext === false) throw new RuntimeException('Déchiffrement du jeton OAuth impossible.');
    return $plaintext;
}

function p50yo_http(string $url, string $method = 'GET', array $headers = [], ?array $form = null): array {
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('cURL indisponible.');

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'PASS50-YouTube-OAuth/1.0',
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form ?? [], '', '&', PHP_QUERY_RFC3986);
    }
    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('Erreur réseau OAuth Google : ' . $error);
    $decoded = json_decode((string)$body, true);
    return [
        'status' => $status,
        'body' => (string)$body,
        'json' => is_array($decoded) ? $decoded : [],
    ];
}

function p50yo_google_error(array $response, string $fallback): RuntimeException {
    $data = is_array($response['json'] ?? null) ? $response['json'] : [];
    $message = trim((string)($data['error_description'] ?? ''));
    $error = $data['error'] ?? null;
    if ($message === '' && is_array($error)) $message = trim((string)($error['message'] ?? ''));
    if ($message === '' && is_string($error)) $message = trim($error);
    return new RuntimeException($message !== '' ? $fallback . ' : ' . $message : $fallback . ' (HTTP ' . (int)($response['status'] ?? 0) . ').');
}

function p50yo_utc_timestamp(?string $value): ?int {
    if ($value === null || trim($value) === '') return null;
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable) {
        return null;
    }
}

function p50yo_connection_for_user(string $userId): ?array {
    $stmt = db()->prepare('SELECT * FROM p50_youtube_oauth_connections WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function p50yo_refresh_access_token(string $userId): string {
    $connection = p50yo_connection_for_user($userId);
    if (!$connection) throw new RuntimeException('Aucune chaîne YouTube connectée.');

    $currentToken = p50yo_decrypt((string)$connection['access_token_encrypted']);
    $expiresAt = p50yo_utc_timestamp((string)$connection['access_expires_at']);
    if ($currentToken !== '' && $expiresAt !== null && $expiresAt > time() + 90) return $currentToken;

    $refreshToken = p50yo_decrypt((string)($connection['refresh_token_encrypted'] ?? ''));
    if ($refreshToken === '') {
        db()->prepare("UPDATE p50_youtube_oauth_connections SET status='reauthorization_required',last_error='Jeton d’actualisation absent.' WHERE user_id=?")
            ->execute([$userId]);
        throw new RuntimeException('La chaîne YouTube doit être reconnectée.');
    }

    $oauth = p50yo_config();
    $response = p50yo_http(
        'https://oauth2.googleapis.com/token',
        'POST',
        ['Content-Type: application/x-www-form-urlencoded'],
        [
            'client_id' => $oauth['client_id'],
            'client_secret' => $oauth['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]
    );
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $error = p50yo_google_error($response, 'Actualisation du jeton YouTube refusée');
        db()->prepare("UPDATE p50_youtube_oauth_connections SET status='reauthorization_required',last_error=? WHERE user_id=?")
            ->execute([$error->getMessage(), $userId]);
        throw $error;
    }

    $newToken = trim((string)($response['json']['access_token'] ?? ''));
    if ($newToken === '') throw new RuntimeException('Google n’a pas retourné de nouveau jeton YouTube.');
    $expiresIn = max(60, (int)($response['json']['expires_in'] ?? 3600));
    $expires = gmdate('Y-m-d H:i:s', time() + $expiresIn);

    db()->prepare("UPDATE p50_youtube_oauth_connections SET access_token_encrypted=?,access_expires_at=?,token_type=?,status='active',last_error=NULL,last_refreshed_at=UTC_TIMESTAMP() WHERE user_id=?")
        ->execute([
            p50yo_encrypt($newToken),
            $expires,
            (string)($response['json']['token_type'] ?? 'Bearer'),
            $userId,
        ]);
    return $newToken;
}

function p50yo_redirect_result(string $status, string $code = ''): never {
    global $config;
    $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    if (!filter_var($base, FILTER_VALIDATE_URL)) $base = '/';
    $query = ['youtube_oauth' => $status];
    if ($code !== '') $query['code'] = $code;
    $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $target = $base === '/' ? '/?' . $queryString : $base . '/?' . $queryString;
    $originParts = parse_url($base);
    $origin = isset($originParts['scheme'], $originParts['host'])
        ? $originParts['scheme'] . '://' . $originParts['host'] . (isset($originParts['port']) ? ':' . $originParts['port'] : '')
        : '*';

    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');

    $message = [
        'source' => 'PASS50_YOUTUBE_OAUTH',
        'status' => $status,
        'code' => $code,
    ];
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PASS50 · YouTube</title></head><body>';
    echo '<p>Connexion YouTube terminée. Cette fenêtre peut être fermée.</p>';
    echo '<script>(function(){var m=' . json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';var t=' . json_encode($target, JSON_UNESCAPED_SLASHES) . ';var o=' . json_encode($origin, JSON_UNESCAPED_SLASHES) . ';try{if(window.opener&&!window.opener.closed){window.opener.postMessage(m,o);window.close();return;}}catch(e){}window.location.replace(t);}());</script>';
    echo '</body></html>';
    exit;
}
