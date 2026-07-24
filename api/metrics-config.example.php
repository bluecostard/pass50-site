<?php
// Copier ces valeurs dans api/config.php ou définir des variables d'environnement.
define('PASS50_YOUTUBE_API_KEY', getenv('PASS50_YOUTUBE_API_KEY') ?: '');
// Optionnel : accès X API v2.
define('PASS50_X_BEARER_TOKEN', getenv('PASS50_X_BEARER_TOKEN') ?: '');
// Jeton secret utilisé par le cron IONOS.
define('PASS50_METRICS_CRON_TOKEN', getenv('PASS50_METRICS_CRON_TOKEN') ?: 'CHANGE-ME-LONG-RANDOM');
