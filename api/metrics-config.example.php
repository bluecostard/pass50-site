<?php
// Copier ces valeurs dans api/config.php ou définir des variables d'environnement.
// Optionnel : accès X API v2.
define('PASS50_X_BEARER_TOKEN', getenv('PASS50_X_BEARER_TOKEN') ?: '');
// Secret HMAC PR5 (32 caractères minimum), conservé uniquement sur le serveur.
// La configuration principale recommandée reste api/config.php : metrics.cron_secret.
define('PASS50_METRICS_CRON_SECRET', getenv('PASS50_METRICS_CRON_SECRET') ?: '');

// Activer la chaîne classement dynamique :
// 1) collecte (orchestrateur)  2) calcul MR-V1.0  3) publication publique
// putenv('PASS50_METRICS_ORCHESTRATOR_ENABLED=true');
// putenv('PASS50_RANKING_PUBLICATION_ENABLED=true');
// putenv('PASS50_RANKING_AUTOMATIC_PUBLICATION_ENABLED=true'); // cron auto
// putenv('PASS50_RANKING_BOOTSTRAP_ALLOWED=true'); // 1er passage si classement figé
