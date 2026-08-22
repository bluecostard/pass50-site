<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/weekly-digest-render-core.php';

require_method('GET');
header('Cache-Control: public, max-age=300, stale-while-revalidate=600');

$week = trim((string)($_GET['week'] ?? ''));
$preview = isset($_GET['preview']) && in_array(strtolower((string)$_GET['preview']), ['1', 'true', 'yes'], true);

try {
    db()->exec("SET time_zone = '+00:00'");
} catch (Throwable) {
}

$stats = p50_weekly_digest_load_stats(db(), $week, $preview);
$view = p50_weekly_digest_view_model($stats);
$pdf = p50_weekly_digest_pdf_bytes($view);
$filename = 'pass50-bilan-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$view['weekKey']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string)strlen($pdf));
header('X-PASS50-Weekly-Digest: PDF-V1');
echo $pdf;
