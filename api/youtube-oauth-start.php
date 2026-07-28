<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/youtube-oauth-core.php';
require __DIR__ . '/youtube-oauth-state-v2.php';

require_method('POST');
set_time_limit(10);

$sessionToken = bearer_token();
if ($sessionToken === null || $sessionToken === '') {
    json_response(['error' => 'Connexion PASS50 requise.'], 401);
}

try {
    // Aucun accès MySQL avant l’ouverture de Google : le jeton de session est
    // seulement haché et placé dans un état OAuth signé, lié au navigateur.
    $oauth = p50yo_config();
    $nonce = p50yo_base64url_encode(random_bytes(24));
    $state = p50yo_create_state(hash('sha256', $sessionToken), $nonce);
    p50yo_set_nonce_cookie($nonce);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + P50YO_STATE_TTL_SECONDS);

    $params = [
        'client_id' => $oauth['client_id'],
        'redirect_uri' => $oauth['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', P50YO_REQUIRED_SCOPES),
        'access_type' => 'offline',
        'include_granted_scopes' => 'true',
        'prompt' => 'consent select_account',
        'state' => $state,
    ];
    $authorizationUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    json_response([
        'ok' => true,
        'flowVersion' => 3,
        'authorizationUrl' => $authorizationUrl,
        'expiresAt' => $expiresAt . 'Z',
    ]);
} catch (Throwable $e) {
    error_log('YouTube OAuth start: ' . $e->getMessage());
    $message = $e->getMessage();
    $safe = str_contains($message, 'Configuration OAuth Google')
        || str_contains($message, 'Clé de chiffrement OAuth')
        || str_contains($message, 'URI de redirection OAuth')
        || str_contains($message, 'Extension OpenSSL')
        || str_contains($message, 'OAuth')
        || str_contains($message, 'cookie');
    json_response([
        'error' => $safe ? $message : 'Le serveur n’a pas pu préparer la connexion YouTube.',
        'diagnostic' => $safe ? 'oauth_configuration' : 'oauth_initialization',
    ], 503);
}
