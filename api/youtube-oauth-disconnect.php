<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/youtube-oauth-core.php';

require_method('POST');
$user = auth_user();
p50yo_ensure_schema();
$userId = (string)$user['id'];
$connection = p50yo_connection_for_user($userId);

$revokedAtGoogle = false;
$warning = null;
if ($connection) {
    try {
        $token = p50yo_decrypt((string)($connection['refresh_token_encrypted'] ?? ''));
        if ($token === '') $token = p50yo_decrypt((string)($connection['access_token_encrypted'] ?? ''));
        if ($token !== '') {
            $response = p50yo_http(
                'https://oauth2.googleapis.com/revoke',
                'POST',
                ['Content-Type: application/x-www-form-urlencoded'],
                ['token' => $token]
            );
            $revokedAtGoogle = $response['status'] >= 200 && $response['status'] < 300;
            if (!$revokedAtGoogle) $warning = 'La connexion locale est supprimée, mais Google n’a pas confirmé la révocation.';
        }
    } catch (Throwable $e) {
        error_log('YouTube OAuth revoke: ' . $e->getMessage());
        $warning = 'La connexion locale est supprimée, mais la révocation Google n’a pas pu être confirmée.';
    }
}

$db = db();
$db->beginTransaction();
try {
    $tableCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_youtube_analytics_snapshots'");
    $tableCheck->execute();
    $analyticsDeleted = 0;
    if ((int)$tableCheck->fetchColumn() === 1) {
        $analyticsDelete = $db->prepare('DELETE FROM p50_youtube_analytics_snapshots WHERE user_id=?');
        $analyticsDelete->execute([$userId]);
        $analyticsDeleted = $analyticsDelete->rowCount();
    }
    $db->prepare('DELETE FROM p50_youtube_oauth_connections WHERE user_id=?')->execute([$userId]);
    $db->prepare('DELETE FROM p50_youtube_oauth_states WHERE user_id=?')->execute([$userId]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

json_response([
    'ok' => true,
    'connected' => false,
    'revokedAtGoogle' => $revokedAtGoogle,
    'privateAnalyticsDeleted' => (int)$analyticsDeleted,
    'warning' => $warning,
]);
