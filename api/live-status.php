<?php
declare(strict_types=1);

// Compatibilité : l'ancien minuteur du navigateur appelle encore cet endpoint.
// Une seule source de vérité évite que le Radar V2 écrase les résultats V4.
if (!isset($_GET['mode'])) $_GET['mode'] = 'quick';
require __DIR__ . '/live-status-v4.php';
