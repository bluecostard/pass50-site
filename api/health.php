<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

require_method('GET');

$dbOk = false;
try {
    db()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    error_log('Health DB: ' . $e->getMessage());
}

global $config;
$oauth = is_array($config['google_oauth'] ?? null) ? $config['google_oauth'] : [];
$oauthConfigured = trim((string)($oauth['client_id'] ?? '')) !== ''
    && trim((string)($oauth['client_secret'] ?? '')) !== ''
    && trim((string)($oauth['redirect_uri'] ?? '')) !== ''
    && trim((string)($oauth['token_encryption_key'] ?? '')) !== '';

json_response([
    'ok' => $dbOk,
    'configLoaded' => true,
    'databaseConnected' => $dbOk,
    'googleOauthConfigured' => $oauthConfigured,
    'phpVersion' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
]);
