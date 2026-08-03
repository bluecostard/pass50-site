<?php
declare(strict_types=1);

/**
 * Envoi push cron (HMAC).
 * POST JSON: { topic, title, body, data?, limit?, dispatchId }
 * Headers: X-P50-Timestamp, X-P50-Signature
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/push-core.php';
require_once __DIR__ . '/metrics-orchestrator-core.php';

require_method('POST');
$in = json_input();
$raw = file_get_contents('php://input') ?: '';
$cfg = p50_push_config();

try {
    $secret = $cfg['cronSecret'] !== '' ? $cfg['cronSecret'] : (string)(p50_mo_config()['cronSecret'] ?? '');
    $ts = (string)($_SERVER['HTTP_X_P50_TIMESTAMP'] ?? '');
    $sig = (string)($_SERVER['HTTP_X_P50_SIGNATURE'] ?? '');
    if (!p50_mo_verify_cron_signature($secret, $ts, $raw, $sig)) {
        json_response(['error' => 'Signature cron invalide.'], 401);
    }

    $topic = trim((string)($in['topic'] ?? 'lives'));
    $title = trim((string)($in['title'] ?? 'PASS50'));
    $body = trim((string)($in['body'] ?? ''));
    if ($body === '') {
        json_response(['error' => 'body requis.'], 422);
    }
    $data = is_array($in['data'] ?? null) ? $in['data'] : [];
    $limit = (int)($in['limit'] ?? 200);
    json_response(p50_push_broadcast(db(), $topic, $title, $body, $data, $limit));
} catch (InvalidArgumentException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('PASS50 push-send-cron: ' . $error->getMessage());
    json_response(['error' => 'Envoi push impossible.'], 500);
}
