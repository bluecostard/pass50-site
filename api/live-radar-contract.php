<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/live-radar-v4-core.php';
require_method('GET');

header('Cache-Control: no-store, max-age=0');
json_response([
    'ok'=>true,
    'contract'=>P50_LIVE_V4_LOGIC_REVISION,
    'trustGate'=>P50_LIVE_V4_TRUST_REVISION,
    'radarVersion'=>'4.3',
    'publicMaxAgeSeconds'=>p50_live_v4_trust_seconds_map(),
    'publicStateWrites'=>0,
]);
