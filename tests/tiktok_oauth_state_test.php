<?php
declare(strict_types=1);

$config = [
    'app' => ['base_url' => 'https://www.pass50.store'],
    'tiktok_oauth' => [
        'client_key' => 'sandbox-client-key',
        'client_secret' => 'sandbox-client-secret',
        'redirect_uri' => 'https://www.pass50.store/api/tiktok-oauth-callback.php',
        'token_encryption_key' => str_repeat('k', 40),
        'environment' => 'sandbox',
    ],
];

require __DIR__ . '/../api/tiktok-oauth-core.php';
require __DIR__ . '/../api/tiktok-oauth-state-v1.php';

$nonce = p50tk_base64url_encode(random_bytes(24));
$sessionHash = hash('sha256', 'session-token');
$state = p50tk_create_state($sessionHash, $nonce);
if (p50tk_verify_state($state, $nonce) !== $sessionHash) throw new RuntimeException('state verification failed');

$failed = false;
try { p50tk_verify_state($state . 'x', $nonce); } catch (Throwable) { $failed = true; }
if (!$failed) throw new RuntimeException('tampered state accepted');

$cipher = p50tk_encrypt('secret-token');
if (p50tk_decrypt($cipher) !== 'secret-token') throw new RuntimeException('encryption roundtrip failed');

echo "TikTok OAuth state test: OK\n";
