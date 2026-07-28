<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/youtube-oauth-core.php';
require __DIR__ . '/youtube-oauth-state-v2.php';

require_method('POST');
set_time_limit(10);
$user = auth_user();

try {
    // La configuration et l’état signé suffisent pour ouvrir Google.
    // Aucune migration ni écriture MySQL n’est effectuée à cette étape.
    $oauth = p50yo_config();
    $state = p50yo_create_state((string)$user['id']);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 10 * 60);

    $params = [
        'client_id' => $oauth['client_id'],
        'redirect_uri' => $oauth['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', P50YO_REQUIRED_SCOPES),
        'access_type' => 'offline',
        'include_granted_scopes' => 'true',
        'prompt' => 'consent select_account',
        'state' => $state,
        'login_hint' => (string)$user['email'],
    ];
    $authorizationUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    json_response([
        'ok' => true,
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
        || str_contains($message, 'état OAuth');
    json_response([
        'error' => $safe ? $message : 'Le serveur n’a pas pu préparer la connexion YouTube.',
        'diagnostic' => $safe ? 'oauth_configuration' : 'oauth_initialization',
    ], 503);
}
