<?php
declare(strict_types=1);

function p50yo_state_key(): string {
    return hash_hmac(
        'sha256',
        'PASS50:youtube-oauth-state:v2',
        p50yo_encryption_key(),
        true
    );
}

function p50yo_create_state(string $userId): string {
    $now = time();
    $payload = [
        'v' => 2,
        'uid' => $userId,
        'iat' => $now,
        'exp' => $now + 10 * 60,
        'nonce' => p50yo_base64url_encode(random_bytes(18)),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Création de l’état OAuth impossible.');

    $encoded = p50yo_base64url_encode($json);
    $signature = p50yo_base64url_encode(hash_hmac('sha256', $encoded, p50yo_state_key(), true));
    return $encoded . '.' . $signature;
}

function p50yo_verify_state(string $state): string {
    if ($state === '' || strlen($state) > 1024 || substr_count($state, '.') !== 1) {
        throw new RuntimeException('État OAuth invalide.');
    }

    [$encoded, $signature] = explode('.', $state, 2);
    $expected = p50yo_base64url_encode(hash_hmac('sha256', $encoded, p50yo_state_key(), true));
    if (!hash_equals($expected, $signature)) {
        throw new RuntimeException('Signature OAuth invalide.');
    }

    $payload = json_decode(p50yo_base64url_decode($encoded), true);
    if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 2) {
        throw new RuntimeException('État OAuth incompatible.');
    }

    $userId = trim((string)($payload['uid'] ?? ''));
    $issuedAt = (int)($payload['iat'] ?? 0);
    $expiresAt = (int)($payload['exp'] ?? 0);
    $now = time();

    if (!preg_match('/^[A-Fa-f0-9-]{36}$/', $userId)) {
        throw new RuntimeException('Utilisateur OAuth invalide.');
    }
    if ($issuedAt < $now - 15 * 60 || $issuedAt > $now + 60 || $expiresAt < $now || $expiresAt > $now + 15 * 60) {
        throw new RuntimeException('État OAuth expiré.');
    }

    return $userId;
}
