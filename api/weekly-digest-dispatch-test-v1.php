<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/notification-core.php';
require_once __DIR__ . '/weekly-digest-core.php';

$user = auth_user();
require_role($user, 'owner', 'admin');

require_method('POST');
$input = json_input();

$scope = strtolower(trim((string)($input['scope'] ?? 'self')));
$force = !empty($input['force']);

if (!in_array($scope, ['self', 'all'], true)) {
    json_response(['error' => 'Scope invalide.'], 422);
}

$pdo = db();
set_time_limit(120);

try {
    if ($scope === 'all') {
        require_role($user, 'owner');
        $dispatchId = 'staff-test-' . (string)$user['id'] . '-' . time();
        $result = p50_weekly_digest_run($pdo, $dispatchId, $force);
        json_response([
            'ok' => true,
            'scope' => 'all',
            'version' => P50_WEEKLY_DIGEST_VERSION,
            'weekKey' => $result['weekKey'] ?? '',
            'sent' => (int)($result['sent'] ?? 0),
            'skipped' => (bool)($result['skipped'] ?? false),
            'message' => $result['message'] ?? null,
            'dispatchId' => $dispatchId,
        ]);
    }

    $stats = p50_weekly_digest_compute_stats($pdo);
    $message = p50_weekly_digest_build_message($stats);
    $notificationId = p50_notification_create(
        $pdo,
        (string)$user['id'],
        $message['title'],
        $message['body'],
        $message['kind'],
        $message['actionUrl']
    );

    json_response([
        'ok' => true,
        'scope' => 'self',
        'version' => P50_WEEKLY_DIGEST_VERSION,
        'weekKey' => (string)$stats['weekKey'],
        'notificationId' => $notificationId,
        'message' => $message,
        'cardUrl' => '/weekly-digest-card.html?week=' . rawurlencode((string)$stats['weekKey']),
    ], 201);
} catch (Throwable $error) {
    error_log('PASS50 weekly digest test dispatch: ' . substr($error->getMessage(), 0, 300));
    json_response(['error' => 'Envoi du bilan test interrompu.'], 500);
}
