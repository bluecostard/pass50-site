<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/youtube-oauth-core.php';

require_method('POST');
$user = auth_user();
p50yo_ensure_schema();
$oauth = p50yo_config();

$state = p50yo_base64url_encode(random_bytes(32));
$stateHash = hash('sha256', $state);
$expiresAt = gmdate('Y-m-d H:i:s', time() + 10 * 60);

$db = db();
$db->beginTransaction();
try {
    $db->prepare('DELETE FROM p50_youtube_oauth_states WHERE expires_at<NOW() OR user_id=?')->execute([$user['id']]);
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
