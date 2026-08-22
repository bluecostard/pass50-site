<?php
declare(strict_types=1);

require __DIR__ . '/api/bootstrap.php';
require __DIR__ . '/api/weekly-digest-render-core.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=120');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$week = trim((string)($_GET['week'] ?? ''));
$preview = isset($_GET['preview']) && in_array(strtolower((string)$_GET['preview']), ['1', 'true', 'yes'], true);
$autoprint = isset($_GET['print']) && in_array(strtolower((string)$_GET['print']), ['1', 'true', 'yes'], true);

try {
    db()->exec("SET time_zone = '+00:00'");
} catch (Throwable) {
}

$stats = p50_weekly_digest_load_stats(db(), $week, $preview);
$view = p50_weekly_digest_view_model($stats);
echo p50_weekly_digest_render_html($view, false);
if ($autoprint) {
    echo '<script>window.addEventListener("load",()=>setTimeout(()=>window.print(),300));</script>';
}
