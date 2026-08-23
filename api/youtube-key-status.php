<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/metrics-core.php';
require_once __DIR__ . '/radar-core.php';

$user = auth_user();
require_role($user, 'owner', 'admin');
require_method('GET');

// Jamais de cache edge : ce statut pilote l’affichage MAJ « Clé configurée ».
header('Cache-Control: private, no-store, max-age=0');
header('Cloudflare-CDN-Cache-Control: no-store');
header('CDN-Cache-Control: no-store');
header('Vary: Authorization, Cookie');

$status = p50m_youtube_key_status();
$radarKey = p50_radar_youtube_key();

json_response([
    'ok' => true,
    'configured' => (bool)$status['configured'],
    'source' => (string)$status['source'],
    'keyLength' => (int)$status['keyLength'],
    'keyPrefix' => (string)$status['keyPrefix'],
    'configFile' => is_file(__DIR__ . '/config.php'),
    'radarAgrees' => ($status['configured'] === ($radarKey !== '')),
    'hint' => $status['configured']
        ? 'Clé visible par PHP. Si la MAJ dit encore « non », purgez Cloudflare puis relancez la MAJ.'
        : 'PHP ne voit aucune clé. Vérifiez api/config.php sur IONOS (hors Cloudflare) et videz OPcache si besoin.',
]);
