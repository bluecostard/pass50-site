<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/tiktok-oauth-core.php';

require_method('POST');
$user = auth_user();
p50tk_ensure_schema();
$userId = (string)$user['id'];
$connection = p50tk_connection_for_user($userId);
$revokedAtTikTok = false;
$warning = null;

if ($connection) {
    try {
        $accessToken = p50tk_decrypt((string)$connection['access_token_encrypted']);
        if ($accessToken !== '') {
            $oauth = p50tk_config();
            $response = p50tk_http(
                'https://open.tiktokapis.com/v2/oauth/revoke/',
                'POST',
                ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                ['client_key' => $oauth['client_key'], 'client_secret' => $oauth['client_secret'], 'token' => $accessToken]
            );
            $revokedAtTikTok = $response['status'] >= 200 && $response['status'] < 300;
            if (!$revokedAtTikTok) $warning = 'La connexion locale est supprimée, mais TikTok n’a pas confirmé la révocation.';
        }
    } catch (Throwable $e) {
        error_log('TikTok OAuth revoke: ' . $e->getMessage());
        $warning = 'La connexion locale est supprimée, mais la révocation TikTok n’a pas pu être confirmée.';
    }
}

$db = db();
$db->beginTransaction();
try {
    $db->prepare('DELETE FROM p50_tiktok_oauth_videos WHERE user_id=?')->execute([$userId]);
    $db->prepare('DELETE FROM p50_tiktok_oauth_connections WHERE user_id=?')->execute([$userId]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

json_response([
    'ok' => true,
    'connected' => false,
    'revokedAtTikTok' => $revokedAtTikTok,
    'warning' => $warning,
]);
