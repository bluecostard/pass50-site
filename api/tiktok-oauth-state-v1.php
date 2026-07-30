<?php
declare(strict_types=1);

const P50TK_STATE_TTL_SECONDS = 600;
const P50TK_STATE_MAX_CLOCK_SKEW_SECONDS = 60;
const P50TK_NONCE_COOKIE = 'p50_tiktok_oauth_nonce';

function p50tk_state_key(): string {
    return hash_hmac('sha256', 'PASS50:tiktok-oauth-state:v1', p50tk_encryption_key(), true);
}

function p50tk_cookie_domain(): string {
    global $config;
    $baseHost = strtolower((string)(parse_url((string)($config['app']['base_url'] ?? ''), PHP_URL_HOST) ?: ''));
    $redirectHost = strtolower((string)(parse_url((string)($config['tiktok_oauth']['redirect_uri'] ?? ''), PHP_URL_HOST) ?: ''));
    $normalize = static fn(string $host): string => preg_replace('/^www\./i', '', trim($host, '.')) ?: '';
    $baseRoot = $normalize($baseHost);
    $redirectRoot = $normalize($redirectHost);
    if ($baseRoot !== '' && $baseRoot === $redirectRoot && str_contains($baseRoot, '.')) return $baseRoot;
    return '';
}

function p50tk_nonce_cookie_options(int $expires): array {
    $options = [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    $domain = p50tk_cookie_domain();
    if ($domain !== '') $options['domain'] = $domain;
    return $options;
}

function p50tk_set_nonce_cookie(string $nonce): void {
    if ($nonce === '' || strlen($nonce) > 256) throw new RuntimeException('Nonce OAuth TikTok invalide.');
    if (!setcookie(P50TK_NONCE_COOKIE, $nonce, p50tk_nonce_cookie_options(time() + P50TK_STATE_TTL_SECONDS))) {
        throw new RuntimeException('Création du cookie OAuth TikTok impossible.');
    }
}

function p50tk_clear_nonce_cookie(): void {
    setcookie(P50TK_NONCE_COOKIE, '', p50tk_nonce_cookie_options(time() - 3600));
}

function p50tk_create_state(string $sessionTokenHash, string $nonce): string {
    if (!preg_match('/^[A-Fa-f0-9]{64}$/', $sessionTokenHash)) throw new RuntimeException('Session OAuth TikTok invalide.');
    if ($nonce === '' || strlen($nonce) > 256) throw new RuntimeException('Nonce OAuth TikTok invalide.');
    $now = time();
    $payload = [
        'v' => 1,
        'sid' => strtolower($sessionTokenHash),
        'nh' => hash('sha256', $nonce),
        'iat' => $now,
        'exp' => $now + P50TK_STATE_TTL_SECONDS,
        'jti' => p50tk_base64url_encode(random_bytes(18)),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $encoded = p50tk_base64url_encode($json);
    $signature = p50tk_base64url_encode(hash_hmac('sha256', $encoded, p50tk_state_key(), true));
    return $encoded . '.' . $signature;
}

function p50tk_verify_state(string $state, string $nonce): string {
    if ($state === '' || strlen($state) > 1024 || substr_count($state, '.') !== 1) {
        throw new RuntimeException('État OAuth TikTok invalide.');
    }
    if ($nonce === '' || strlen($nonce) > 256) throw new RuntimeException('Cookie OAuth TikTok absent ou invalide.');
    [$encoded, $signature] = explode('.', $state, 2);
    $expected = p50tk_base64url_encode(hash_hmac('sha256', $encoded, p50tk_state_key(), true));
    if (!hash_equals($expected, $signature)) throw new RuntimeException('Signature OAuth TikTok invalide.');
    $payload = json_decode(p50tk_base64url_decode($encoded), true);
    if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) throw new RuntimeException('État OAuth TikTok incompatible.');
    $sessionTokenHash = strtolower(trim((string)($payload['sid'] ?? '')));
    $nonceHash = strtolower(trim((string)($payload['nh'] ?? '')));
    $issuedAt = (int)($payload['iat'] ?? 0);
    $expiresAt = (int)($payload['exp'] ?? 0);
    $now = time();
    if (!preg_match('/^[a-f0-9]{64}$/', $sessionTokenHash)) throw new RuntimeException('Session OAuth TikTok invalide.');
    if (!preg_match('/^[a-f0-9]{64}$/', $nonceHash) || !hash_equals($nonceHash, hash('sha256', $nonce))) {
        throw new RuntimeException('Navigateur OAuth TikTok non reconnu.');
    }
    if (
        $issuedAt < $now - P50TK_STATE_TTL_SECONDS - P50TK_STATE_MAX_CLOCK_SKEW_SECONDS
        || $issuedAt > $now + P50TK_STATE_MAX_CLOCK_SKEW_SECONDS
        || $expiresAt < $now
        || $expiresAt > $now + P50TK_STATE_TTL_SECONDS + P50TK_STATE_MAX_CLOCK_SKEW_SECONDS
    ) {
        throw new RuntimeException('État OAuth TikTok expiré.');
    }
    return $sessionTokenHash;
}
