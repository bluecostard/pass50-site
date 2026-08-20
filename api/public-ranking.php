<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/public-ranking-core.php';

/**
 * API publique classement — contrat app V1.
 * GET  : snapshot slim (Top 50 × périodes), cacheable
 * POST : rebuild admin (seed après deploy, sans attendre une publication)
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $period = strtoupper(trim((string)($_GET['period'] ?? '')));
        $limit = (int)($_GET['limit'] ?? P50_PUBLIC_RANKING_LIMIT);
        $limit = max(1, min(P50_PUBLIC_RANKING_LIMIT, $limit));
        $payload = p50_public_ranking_response($pdo);
        if ($period !== '' && isset($payload['periods'][$period]) && is_array($payload['periods'][$period])) {
            $payload['period'] = $period;
            $payload['rows'] = array_slice($payload['periods'][$period], 0, $limit);
        }
        // Clients app / desktop : cache courte pour encaisser un pic de lectures.
        header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
        json_response($payload);
    }

    require_method('POST');
    $user = auth_user();
    require_role($user, 'owner', 'admin');
    $in = json_input();
    $action = strtolower(trim((string)($in['action'] ?? 'rebuild')));
    if ($action !== 'rebuild') {
        json_response(['error' => 'Action invalide.'], 422);
    }
    $snapshot = p50_public_ranking_rebuild_and_persist($pdo, [
        'publishedAt' => gmdate('c'),
    ]);
    header('Cache-Control: no-store');
    json_response($snapshot);
} catch (InvalidArgumentException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('PASS50 public ranking: ' . $error->getMessage());
    json_response(['error' => 'Classement public indisponible.'], 500);
}
