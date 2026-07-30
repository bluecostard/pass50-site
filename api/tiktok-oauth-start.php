<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tiktok-oauth-core.php';
require __DIR__ . '/tiktok-oauth-state-v1.php';

require_method('POST');
set_time_limit(10);

$sessionToken = bearer_token();
if ($sessionToken === null || $sessionToken === '') {
    json_response(['error' => 'Connexion PASS50 requise.'], 401);
}

try {
    $oauth = p50tk_config();
    $nonce = p50tk_base64url_encode(random_bytes(24));
    $state = p50tk_create_state(hash('sha256', $sessionToken), $nonce);
    p50tk_set_nonce_cookie($nonce);
    $params = [
        'client_key' => $oauth['client_key'],
        'scope' => implode(',', P50TK_REQUIRED_SCOPES),
        'response_type' => 'code',
        'redirect_uri' => $oauth['redirect_uri'],
        'state' => $state,
        'disable_auto_auth' => 1,
    ];
    json_response([
        'ok' => true,
        'environment' => $oauth['environment'],
        'authorizationUrl' => 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
        'expiresAt' => gmdate('c', time() + P50TK_STATE_TTL_SECONDS),
    ]);
} catch (Throwable $e) {
    error_log('TikTok OAuth start: ' . $e->getMessage());
    $message = $e->getMessage();
    $safe = str_contains($message, 'Configuration OAuth TikTok')
        || str_contains($message, 'URI de redirection OAuth TikTok')
        || str_contains($message, 'Clé de chiffrement OAuth')
        || str_contains($message, 'cookie');
    json_response([
        'error' => $safe ? $message : 'Le serveur n’a pas pu préparer la connexion TikTok.',
        'diagnostic' => $safe ? 'oauth_configuration' : 'oauth_initialization',
    ], 503);
}
