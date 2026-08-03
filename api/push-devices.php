<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/push-core.php';

require_method('POST');
$user = auth_user(false);
$in = json_input();
$action = trim((string)($in['action'] ?? 'register'));

try {
    $pdo = db();
    if ($action === 'unregister') {
        json_response(p50_push_unregister($pdo, (string)($in['deviceId'] ?? '')));
    }
    if ($action !== 'register') {
        json_response(['error' => 'Action invalide.'], 422);
    }
    json_response(p50_push_register($pdo, $in, $user));
} catch (InvalidArgumentException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('PASS50 push-devices: ' . $error->getMessage());
    json_response(['error' => 'Enregistrement push impossible.'], 500);
}
