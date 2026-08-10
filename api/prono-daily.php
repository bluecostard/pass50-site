<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-daily-core.php';

p50_prono_ensure_schema();

$user = auth_user();
require_role($user, 'owner', 'admin');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $batchDate = trim((string)($_GET['batchDate'] ?? ''));
    if ($batchDate === '') {
        $batchDate = p50_prono_now()->format('Y-m-d');
    }
    $items = p50_prono_daily_list(db(), $batchDate);
    json_response([
        'ok' => true,
        'version' => P50_PRONO_VERSION,
        'batchDate' => $batchDate,
        'count' => count($items),
        'draftCount' => count(array_filter($items, static fn(array $i): bool => ($i['status'] ?? '') === 'draft')),
        'items' => $items,
    ]);
}

require_method('POST');
$input = json_input();
$action = trim((string)($input['action'] ?? 'generate'));
$batchDate = trim((string)($input['batchDate'] ?? ''));
if ($batchDate === '') {
    $batchDate = p50_prono_now()->format('Y-m-d');
}

$pdo = db();

if ($action === 'publish') {
    $result = p50_prono_daily_publish($pdo, $batchDate);
    if ($result['published'] === 0 && ($result['errors'] ?? []) !== []) {
        json_response([
            'ok' => false,
            'errors' => $result['errors'],
            'published' => 0,
        ], 409);
    }
    json_response([
        'ok' => true,
        'published' => $result['published'],
        'items' => $result['items'],
        'errors' => $result['errors'],
    ]);
}

if ($action === 'generate') {
    try {
        $result = p50_prono_daily_generate($pdo, (string)$user['id'], $batchDate);
    } catch (InvalidArgumentException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }
    if (($result['items'] ?? []) === []) {
        json_response([
            'ok' => false,
            'message' => $result['message'],
            'batchDate' => $result['batchDate'],
        ], 422);
    }
    json_response([
        'ok' => true,
        'batchId' => $result['batchId'],
        'batchDate' => $result['batchDate'],
        'count' => count($result['items']),
        'message' => $result['message'],
        'items' => $result['items'],
    ]);
}

json_response(['error' => 'Action invalide. Utilise generate ou publish.'], 400);
