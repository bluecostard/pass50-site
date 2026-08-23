<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/metrics-core.php';
require_once __DIR__ . '/radar-core.php';

$user = auth_user();
require_role($user, 'owner', 'admin');
require_method('GET');

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
        ? 'Clé visible par PHP. Si la MAJ dit encore « non », rechargez sans cache puis relancez.'
        : 'PHP ne voit aucune clé. Vérifiez que le fichier édité est bien api/config.php (pas un .bak) et videz OPcache si besoin.',
]);
