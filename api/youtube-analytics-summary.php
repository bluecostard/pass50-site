<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/youtube-analytics-core.php';

require_method('GET', 'POST');
$user = auth_user();
$userId = (string)$user['id'];
$days = p50ya_days((int)($_GET['days'] ?? P50YA_DEFAULT_DAYS));

try {
    $connection = p50ya_connection($userId);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $summary = p50ya_latest_summary($userId, $days, (string)$connection['channel_id']);
        json_response([
            'ok' => true,
            'connected' => true,
            'channel' => [
                'id' => (string)$connection['channel_id'],
                'title' => (string)$connection['channel_title'],
            ],
            'summary' => $summary,
            'privateAnalytics' => true,
            'affectsPublicRanking' => false,
        ]);
    }

    $input = json_input();
    if (isset($input['days'])) $days = p50ya_days((int)$input['days']);
    $lock = 'pass50_youtube_analytics_' . hash('sha256', $userId . '|' . $days);
    $lockStmt = db()->prepare('SELECT GET_LOCK(?,2)');
    $lockStmt->execute([$lock]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        json_response(['error' => 'Une actualisation YouTube Analytics est déjà en cours.'], 409);
    }
    try {
        $summary = p50ya_fetch_summary($userId, $days);
    } finally {
        try {
            $release = db()->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        } catch (Throwable) {}
    }
    json_response([
        'ok' => true,
        'connected' => true,
        'channel' => [
            'id' => (string)$connection['channel_id'],
            'title' => (string)$connection['channel_title'],
        ],
        'summary' => $summary,
        'privateAnalytics' => true,
        'affectsPublicRanking' => false,
    ]);
} catch (Throwable $error) {
    error_log('YouTube Analytics summary: ' . $error->getMessage());
    $message = $error->getMessage();
    if (str_contains($message, 'Aucune chaîne YouTube connectée')) {
        json_response(['error' => $message, 'connected' => false], 409);
    }
    if (str_contains($message, 'reconnectée') || str_contains($message, 'autorisations')) {
        json_response(['error' => 'La chaîne YouTube doit être reconnectée pour lire Analytics.', 'reauthorizationRequired' => true], 403);
    }
    json_response(['error' => 'Les statistiques YouTube Analytics sont momentanément indisponibles.'], 502);
}
