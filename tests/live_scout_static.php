<?php
declare(strict_types=1);

/**
 * Tests unitaires légers Live Scout (sans réseau / sans DB).
 * Exécution : php tests/live_scout_static.php
 */

require dirname(__DIR__).'/api/http-tools.php';

// Charger uniquement les helpers purs en mockant le minimum.
function db(): PDO {
    throw new RuntimeException('DB non requise pour ce test statique');
}
function p50_de_ensure_schema(): void {}

require dirname(__DIR__).'/api/live-scout-core.php';

$failed = 0;
function assert_true(bool $cond, string $msg): void {
    global $failed;
    if ($cond) {
        echo "OK  $msg\n";
        return;
    }
    $failed++;
    echo "FAIL $msg\n";
}

$yt = p50_scout_identity('YouTube', 'https://www.youtube.com/@exemple');
assert_true(str_ends_with($yt['liveUrl'], '/live'), 'YouTube liveUrl se termine par /live');

$tt = p50_scout_identity('TikTok', 'https://www.tiktok.com/@demo_user');
assert_true($tt['handle'] === 'demo_user', 'TikTok handle extrait');
assert_true($tt['liveUrl'] === 'https://www.tiktok.com/@demo_user/live', 'TikTok liveUrl');

$ig = p50_scout_identity('Instagram', 'https://www.instagram.com/demo_user/');
assert_true($ig['handle'] === 'demo_user', 'Instagram handle extrait');

$fb = p50_scout_identity('Facebook', 'https://www.facebook.com/DemoPage');
assert_true($fb['handle'] === 'DemoPage', 'Facebook handle extrait');

assert_true(p50_scout_is_direct_url('TikTok', 'https://www.tiktok.com/@demo_user'), 'TikTok URL directe OK');
assert_true(!p50_scout_is_direct_url('Instagram', 'https://www.instagram.com/explore/'), 'Instagram explore refusée');

$manual = p50_scout_target_from_url('https://www.youtube.com/@demo/live', 'Demo');
assert_true($manual !== null && $manual['platform'] === 'YouTube', 'target_from_url YouTube');

$ytLive = p50_scout_detect_youtube(
    ['id' => 't1', 'profileId' => 'p1', 'name' => 'Demo', 'handle' => '@demo', 'platform' => 'YouTube', 'url' => 'https://www.youtube.com/@demo', 'liveUrl' => 'https://www.youtube.com/@demo/live'],
    ['channel_live' => ['ok' => true, 'status' => 200, 'body' => '"isLiveNow":true,"videoId":"abcdefg1234"', 'finalUrl' => 'https://www.youtube.com/watch?v=abcdefg1234', 'timeMs' => 120, 'error' => '']]
);
assert_true($ytLive['state'] === 'live' && $ytLive['confidence'] >= 90, 'YouTube isLiveNow → live');

$ytOff = p50_scout_detect_youtube(
    ['id' => 't2', 'profileId' => 'p1', 'name' => 'Demo', 'handle' => '@demo', 'platform' => 'YouTube', 'url' => 'https://www.youtube.com/@demo', 'liveUrl' => 'https://www.youtube.com/@demo/live'],
    ['channel_live' => ['ok' => true, 'status' => 200, 'body' => '<html><title>Offline - YouTube</title></html>', 'finalUrl' => 'https://www.youtube.com/@demo/live', 'timeMs' => 80, 'error' => '']]
);
assert_true($ytOff['state'] === 'offline', 'YouTube sans signal → offline');

$summary = p50_scout_summarize([$ytLive, $ytOff]);
assert_true($summary['live'] === 1 && $summary['offline'] === 1 && $summary['scanned'] === 2, 'summarize compte live/offline');

echo $failed === 0 ? "\nTous les tests Live Scout OK\n" : "\n$failed échec(s)\n";
exit($failed === 0 ? 0 : 1);
