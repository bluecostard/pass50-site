<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/youtube-oauth-core.php';

p50yo_ensure_schema();

$state = trim((string)($_GET['state'] ?? ''));
if ($state === '' || strlen($state) > 512) p50yo_redirect_result('error', 'missing_state');

$stateHash = hash('sha256', $state);
$db = db();
$userId = '';

try {
    $db->beginTransaction();
    $stmt = $db->prepare('SELECT user_id,expires_at,consumed_at FROM p50_youtube_oauth_states WHERE state_hash=? FOR UPDATE');
    $stmt->execute([$stateHash]);
    $oauthState = $stmt->fetch();
    if (!$oauthState || $oauthState['consumed_at'] !== null || (p50yo_utc_timestamp((string)$oauthState['expires_at']) ?? 0) < time()) {
        throw new RuntimeException('État OAuth invalide ou expiré.');
    }
    $userId = (string)$oauthState['user_id'];
    $db->prepare('UPDATE p50_youtube_oauth_states SET consumed_at=UTC_TIMESTAMP() WHERE state_hash=?')->execute([$stateHash]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('YouTube OAuth state: ' . $e->getMessage());
    p50yo_redirect_result('error', 'invalid_state');
}

if (isset($_GET['error'])) {
    error_log('YouTube OAuth denied: ' . (string)$_GET['error']);
    p50yo_redirect_result('cancelled', 'access_denied');
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') p50yo_redirect_result('error', 'missing_code');

try {
    $oauth = p50yo_config();
    $tokenResponse = p50yo_http(
        'https://oauth2.googleapis.com/token',
        'POST',
        ['Content-Type: application/x-www-form-urlencoded'],
        [
            'client_id' => $oauth['client_id'],
            'client_secret' => $oauth['client_secret'],
            'code' => $code,
            'redirect_uri' => $oauth['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]
    );
    if ($tokenResponse['status'] < 200 || $tokenResponse['status'] >= 300) {
        throw p50yo_google_error($tokenResponse, 'Échange du code OAuth refusé');
    }

    $tokens = $tokenResponse['json'];
    $accessToken = trim((string)($tokens['access_token'] ?? ''));
    if ($accessToken === '') throw new RuntimeException('Jeton d’accès YouTube absent.');

    $grantedScopes = array_values(array_filter(preg_split('/\s+/', trim((string)($tokens['scope'] ?? ''))) ?: []));
    $missingScopes = array_values(array_diff(P50YO_REQUIRED_SCOPES, $grantedScopes));
    if ($missingScopes) {
        p50yo_http(
            'https://oauth2.googleapis.com/revoke',
            'POST',
            ['Content-Type: application/x-www-form-urlencoded'],
            ['token' => $accessToken]
        );
        throw new RuntimeException('Les autorisations YouTube requises n’ont pas toutes été accordées.');
    }

    $channelResponse = p50yo_http(
        'https://www.googleapis.com/youtube/v3/channels?part=id%2Csnippet&mine=true',
        'GET',
        ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
    );
    if ($channelResponse['status'] < 200 || $channelResponse['status'] >= 300) {
        throw p50yo_google_error($channelResponse, 'Lecture de la chaîne YouTube impossible');
    }
    $channel = $channelResponse['json']['items'][0] ?? null;
    if (!is_array($channel) || trim((string)($channel['id'] ?? '')) === '') {
        throw new RuntimeException('Ce compte Google ne possède aucune chaîne YouTube accessible.');
    }

    $existing = p50yo_connection_for_user($userId);
    $newRefreshToken = trim((string)($tokens['refresh_token'] ?? ''));
    $sameExistingChannel = $existing && hash_equals((string)$existing['channel_id'], (string)$channel['id']);
    $refreshTokenEncrypted = $newRefreshToken !== ''
        ? p50yo_encrypt($newRefreshToken)
        : ($sameExistingChannel ? (string)($existing['refresh_token_encrypted'] ?? '') : '');
    if ($refreshTokenEncrypted === '') {
        throw new RuntimeException('Google n’a pas fourni de jeton d’actualisation. Recommencez la connexion et accordez le consentement.');
    }

    $snippet = is_array($channel['snippet'] ?? null) ? $channel['snippet'] : [];
    $thumbnails = is_array($snippet['thumbnails'] ?? null) ? $snippet['thumbnails'] : [];
    $thumbnail = (string)($thumbnails['high']['url'] ?? $thumbnails['medium']['url'] ?? $thumbnails['default']['url'] ?? '');
    $expiresIn = max(60, (int)($tokens['expires_in'] ?? 3600));
    $accessExpiresAt = gmdate('Y-m-d H:i:s', time() + $expiresIn);

    $sql = "INSERT INTO p50_youtube_oauth_connections
        (user_id,channel_id,channel_title,channel_custom_url,channel_thumbnail_url,access_token_encrypted,refresh_token_encrypted,token_type,scopes,access_expires_at,status,last_error,connected_at,last_refreshed_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,'active',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
          channel_id=VALUES(channel_id),channel_title=VALUES(channel_title),channel_custom_url=VALUES(channel_custom_url),
          channel_thumbnail_url=VALUES(channel_thumbnail_url),access_token_encrypted=VALUES(access_token_encrypted),
          refresh_token_encrypted=VALUES(refresh_token_encrypted),token_type=VALUES(token_type),scopes=VALUES(scopes),
          access_expires_at=VALUES(access_expires_at),status='active',last_error=NULL,connected_at=UTC_TIMESTAMP(),last_refreshed_at=UTC_TIMESTAMP()";
    db()->prepare($sql)->execute([
        $userId,
        (string)$channel['id'],
        (string)($snippet['title'] ?? ''),
        (string)($snippet['customUrl'] ?? ''),
        $thumbnail,
        p50yo_encrypt($accessToken),
        $refreshTokenEncrypted,
        (string)($tokens['token_type'] ?? 'Bearer'),
        implode(' ', $grantedScopes),
        $accessExpiresAt,
    ]);

    db()->prepare('DELETE FROM p50_youtube_oauth_states WHERE user_id=? OR expires_at<UTC_TIMESTAMP()')->execute([$userId]);
    p50yo_redirect_result('connected');
} catch (Throwable $e) {
    error_log('YouTube OAuth callback: ' . $e->getMessage());
    p50yo_redirect_result('error', 'connection_failed');
}
