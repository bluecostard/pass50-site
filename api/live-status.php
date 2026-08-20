<?php
declare(strict_types=1);

// Compatibilité navigateur : lecture cache-only par défaut (plus de scrape à chaque hit).
// Scans : ?mode=quick|full|profile (admin / cron / bouton radar).
if (!isset($_GET['mode']) || trim((string)$_GET['mode']) === '') {
    $_GET['mode'] = 'status';
}
require __DIR__ . '/live-status-v4.php';
