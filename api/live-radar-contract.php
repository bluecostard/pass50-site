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
    'radarVersion'=>'4.6',
    'coverageRevision'=>P50_LIVE_V4_COVERAGE_REVISION,
    'publicMaxAgeSeconds'=>p50_live_v4_trust_seconds_map(),
    'publicStateWrites'=>0,
    // RÈGLE FIGÉE PASS50_LIVE_RADAR_AUTONOMY_V1 — détection hors app, 24/7, tick 1 s.
    'autonomy'=>[
        'revision'=>P50_LIVE_RADAR_AUTONOMY_REVISION,
        'requiresAppOpen'=>P50_LIVE_RADAR_REQUIRES_APP_OPEN,
        'runs24x7'=>true,
        'detectionOwner'=>P50_LIVE_RADAR_DETECTION_OWNER,
        'continuousTickSeconds'=>P50_LIVE_RADAR_CONTINUOUS_TICK_SECONDS,
        'clientRole'=>'cache_read_only',
        'schedules'=>[
            'p0Continuous'=>'*/5 * * * * (boucle 1 Hz ~280 s)',
            'fullSweep'=>'*/5 * * * *',
            'quick'=>'*/5 * * * *',
            'unknownAudit'=>'20 */3 * * *',
        ],
    ],
]);
