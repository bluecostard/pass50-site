<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tiktok-oauth-core.php';
require __DIR__ . '/tiktok-oauth-state-v1.php';

set_time_limit(40);

$state = trim((string)($_GET['state'] ?? ''));
$nonce = trim((string)($_COOKIE[P50TK_NONCE_COOKIE] ?? ''));
try {
    $sessionTokenHash = p50tk_verify_state($state, $nonce);
    p50tk_clear_nonce_cookie();
} catch (Throwable $e) {
    p50tk_clear_nonce_cookie();
    error_log('TikTok OAuth state: ' . $e->getMessage());
    p50tk_redirect_result('error', 'invalid_state');
}

if (isset($_GET['error'])) {
    error_log('TikTok OAuth denied: ' . (string)$_GET['error']);
    p50tk_redirect_result('cancelled', 'access_denied');
}
$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') p50tk_redirect_result('error', 'missing_code');

$issuedAccessToken = '';
$oauth = null;
try {
    try { db()->exec('SET SESSION lock_wait_timeout=5'); } catch (Throwable) {}
    try { db()->exec('SET SESSION max_statement_time=5'); } catch (Throwable) {}
    $sessionStmt = db()->prepare(
        'SELECT u.id,u.email FROM sessions s JOIN users u ON u.id=s.user_id '
        . 'WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP() AND u.deleted_at IS NULL LIMIT 1'
    );
    $sessionStmt->execute([$sessionTokenHash]);
    $sessionUser = $sessionStmt->fetch();
    if (!is_array($sessionUser) || trim((string)($sessionUser['id'] ?? '')) === '') {
        p50tk_redirect_result('error', 'pass50_session_expired');
    }
    $userId = (string)$sessionUser['id'];
    $oauth = p50tk_config();
    $response = p50tk_http(
        'https://open.tiktokapis.com/v2/oauth/token/',
        'POST',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json', 'Cache-Control: no-cache'],
        [
            'client_key' => $oauth['client_key'],
            'client_secret' => $oauth['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $oauth['redirect_uri'],
        ]
    );
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw p50tk_api_error($response, 'Échange du code OAuth TikTok refusé');
    }
    $tokens = $response['json'];
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    if ($accessToken === '' || $refreshToken === '') throw new RuntimeException('Jetons TikTok absents.');
    $issuedAccessToken = $accessToken;
    $grantedScopes = array_values(array_filter(array_map('trim', explode(',', (string)($tokens['scope'] ?? '')))));
    $missingScopes = array_values(array_diff(P50TK_REQUIRED_SCOPES, $grantedScopes));
    if ($missingScopes) {
        throw new RuntimeException('Les autorisations TikTok requises n’ont pas toutes été accordées.');
    }
    $profile = p50tk_fetch_profile($accessToken);
    $videos = p50tk_fetch_videos($accessToken, 10);
    p50tk_store_snapshot($userId, $tokens, $profile, $videos);
    $issuedAccessToken = '';
    p50tk_redirect_result('connected');
} catch (Throwable $e) {
    if ($issuedAccessToken !== '' && is_array($oauth)) {
        try {
            p50tk_http(
                'https://open.tiktokapis.com/v2/oauth/revoke/',
                'POST',
                ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                [
                    'client_key' => $oauth['client_key'],
                    'client_secret' => $oauth['client_secret'],
                    'token' => $issuedAccessToken,
                ]
            );
        } catch (Throwable $revokeError) {
            error_log('TikTok OAuth cleanup: ' . $revokeError->getMessage());
        }
    }
    error_log('TikTok OAuth callback: ' . $e->getMessage());
    p50tk_redirect_result('error', 'connection_failed');
}
