<?php
// Copier ces valeurs dans api/config.php ou définir des variables d'environnement.
// Optionnel : accès X API v2.
define('PASS50_X_BEARER_TOKEN', getenv('PASS50_X_BEARER_TOKEN') ?: '');
// Secret HMAC PR5 (32 caractères minimum), conservé uniquement sur le serveur.
// La configuration principale recommandée reste api/config.php : metrics.cron_secret.
define('PASS50_METRICS_CRON_SECRET', getenv('PASS50_METRICS_CRON_SECRET') ?: '');
