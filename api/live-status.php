<?php
declare(strict_types=1);

// Compatibilité navigateur : lecture cache-only par défaut (plus de scrape à chaque hit).
// Scans : ?mode=quick|full|profile (admin / cron / bouton radar).
if (!isset($_GET['mode']) || trim((string)$_GET['mode']) === '') {
    $_GET['mode'] = 'status';
}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'status')));
$force = isset($_GET['force']) && in_array(strtolower((string)$_GET['force']), ['1', 'true', 'yes'], true);

// Chemin rapide public : snapshot + Cache-Control (pas de blob app_state / sync registry).
if ($mode === 'status' && !$force) {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/data-engine-core.php';
    require __DIR__ . '/live-radar-v4-core.php';
    require __DIR__ . '/live-status-cache-core.php';
    require_method('GET');
    try {
        db()->exec("SET time_zone = '+00:00'");
    } catch (Throwable) {
    }
    p50_live_status_cache_respond();
    exit;
}

require __DIR__ . '/live-status-v4.php';
