<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/youtube-oauth-core.php';

require_method('GET');
$user = auth_user();
p50yo_ensure_schema();
$connection = p50yo_connection_for_user((string)$user['id']);

if (!$connection) {
    json_response(['ok' => true, 'connected' => false]);
}

$expiresAt = (string)$connection['access_expires_at'];
$expiresTs = p50yo_utc_timestamp($expiresAt);
json_response([
    'ok' => true,
    'connected' => $connection['status'] === 'active' || $connection['status'] === 'reauthorization_required',
    'status' => $connection['status'],
    'channel' => [
        'id' => $connection['channel_id'],
        'title' => $connection['channel_title'],
        'customUrl' => $connection['channel_custom_url'],
        'thumbnailUrl' => $connection['channel_thumbnail_url'],
    ],
    'scopes' => preg_split('/\s+/', trim((string)$connection['scopes'])) ?: [],
    'accessExpiresAt' => $expiresAt !== '' ? $expiresAt . 'Z' : null,
    'accessTokenExpired' => $expiresTs === null || $expiresTs <= time(),
    'canRefresh' => !empty($connection['refresh_token_encrypted']),
    'connectedAt' => (string)$connection['connected_at'] . 'Z',
    'lastRefreshedAt' => $connection['last_refreshed_at'] ? (string)$connection['last_refreshed_at'] . 'Z' : null,
    'requiresReauthorization' => $connection['status'] === 'reauthorization_required',
]);
