<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/metrics-orchestrator-core.php';
require __DIR__ . '/notification-core.php';
require __DIR__ . '/weekly-digest-core.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Méthode refusée.'], 405);
}

$contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
if (!preg_match('~^application/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$~', $contentType)) {
    json_response(['error' => 'Type de contenu refusé.'], 415);
}

$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 16384) {
    json_response(['error' => 'Corps trop volumineux.'], 413);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 16384) {
    json_response(['error' => 'Corps invalide.'], 413);
}

$cfg = p50_mo_config();
$secret = (string)$cfg['cronSecret'];
if (!$cfg['enabled']) {
    json_response(['error' => 'Orchestrateur métrique désactivé.'], 503);
}
if (strlen($secret) < 32) {
    json_response(['error' => 'Cron métrique non configuré.'], 503);
}

$timestamp = trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP'] ?? ''));
$signature = strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE'] ?? '')));
if (!preg_match('/^\d{10}$/', $timestamp) || abs(time() - (int)$timestamp) > 300) {
    json_response(['error' => 'Horodatage refusé.'], 401);
}
if (!p50_mo_verify_cron_signature($secret, $timestamp, $raw, $signature)) {
    json_response(['error' => 'Signature refusée.'], 401);
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    json_response(['error' => 'JSON invalide.'], 422);
}

$action = (string)($input['action'] ?? '');
$dispatchId = trim((string)($input['dispatchId'] ?? ''));
$force = !empty($input['force']);

if ($dispatchId === '' || strlen($dispatchId) > 120 || !preg_match('/^[A-Za-z0-9._-]+$/', $dispatchId)) {
    json_response(['error' => 'dispatchId invalide.'], 422);
}

if (!in_array($action, ['digest', 'preview'], true)) {
    json_response(['error' => 'Action invalide.'], 422);
}

set_time_limit(180);
$started = microtime(true);

try {
    if ($action === 'preview') {
        $stats = p50_weekly_digest_compute_stats(db());
        $message = p50_weekly_digest_build_message($stats);
        json_response([
            'ok' => true,
            'version' => P50_WEEKLY_DIGEST_VERSION,
            'action' => 'preview',
            'weekKey' => $stats['weekKey'],
            'message' => $message,
            'stats' => $stats,
            'subscriberCount' => count(p50_weekly_digest_subscriber_ids(db())),
            'alreadySent' => p50_weekly_digest_already_sent(db(), (string)$stats['weekKey']),
            'dispatchId' => $dispatchId,
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
        ]);
    }

    $result = p50_weekly_digest_run(db(), $dispatchId, $force);
    $result['durationMs'] = (int)round((microtime(true) - $started) * 1000);
    json_response($result);
} catch (Throwable $error) {
    error_log('PASS50 weekly digest cron: ' . substr($error->getMessage(), 0, 300));
    json_response([
        'error' => 'Diffusion du bilan hebdomadaire interrompue.',
        'version' => P50_WEEKLY_DIGEST_VERSION,
        'dispatchId' => $dispatchId,
    ], 500);
}
