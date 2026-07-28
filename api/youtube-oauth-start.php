<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/youtube-oauth-core.php';

require_method('POST');
set_time_limit(20);
$user = auth_user();

try {
    // Vérifier d’abord la configuration privée : inutile de lancer une migration si elle est incomplète.
    $oauth = p50yo_config();

    // Éviter qu’une attente de verrou MySQL bloque indéfiniment le clic utilisateur.
    try { db()->exec('SET SESSION lock_wait_timeout=5'); } catch (Throwable) {}
    p50yo_ensure_schema();

    $state = p50yo_base64url_encode(random_bytes(32));
    $stateHash = hash('sha256', $state);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 10 * 60);

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM p50_youtube_oauth_states WHERE expires_at<UTC_TIMESTAMP() OR user_id=?')->execute([$user['id']]);
        $db->prepare('INSERT INTO p50_youtube_oauth_states(state_hash,user_id,expires_at) VALUES(?,?,?)')
            ->execute([$stateHash, $user['id'], $expiresAt]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

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
        || str_contains($message, 'Migration OAuth YouTube');
    json_response([
        'error' => $safe ? $message : 'Le serveur n’a pas pu initialiser la connexion YouTube. Réessaie dans quelques secondes.',
        'diagnostic' => $safe ? 'oauth_configuration' : 'oauth_initialization',
    ], 503);
}
