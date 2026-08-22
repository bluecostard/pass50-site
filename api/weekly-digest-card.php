<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/weekly-digest-render-core.php';

require_method('GET');
header('Cache-Control: public, max-age=120, stale-while-revalidate=300');

$week = trim((string)($_GET['week'] ?? ''));
$preview = isset($_GET['preview']) && in_array(strtolower((string)$_GET['preview']), ['1', 'true', 'yes'], true);

try {
    db()->exec("SET time_zone = '+00:00'");
} catch (Throwable) {
}

$stats = p50_weekly_digest_load_stats(db(), $week, $preview);

json_response([
    'ok' => true,
    'version' => P50_WEEKLY_DIGEST_VERSION,
    'stats' => $stats,
    'view' => p50_weekly_digest_view_model($stats, db()),
    'message' => p50_weekly_digest_build_message($stats),
    'publicUrl' => p50_weekly_digest_page_url((string)$stats['weekKey']),
    'pdfUrl' => p50_weekly_digest_pdf_url((string)$stats['weekKey']),
]);
