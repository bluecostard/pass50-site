<?php
declare(strict_types=1);

require __DIR__.'/../api/trigger-sync-core.php';

$cases = [
    'auto_fresh' => [
        'autoSynced' => true,
        'originalLinkValidatedAt' => gmdate('c', time() - 3600),
        'expected' => false,
    ],
    'auto_stale' => [
        'autoSynced' => true,
        'originalLinkValidatedAt' => gmdate('c', time() - 170 * 3600),
        'expected' => true,
    ],
    'manual_fresh' => [
        'manualDataValidated' => true,
        'originalLinkValidatedAt' => gmdate('c', time() - 2 * 86400),
        'expected' => false,
    ],
    'manual_stale' => [
        'manualDataValidated' => true,
        'originalLinkValidatedAt' => gmdate('c', time() - 8 * 86400),
        'expected' => true,
    ],
];

$failed = 0;
foreach ($cases as $name => $case) {
    $event = $case;
    unset($event['expected']);
    $actual = p50_trigger_event_is_stale($event);
    if ($actual !== $case['expected']) {
        echo "FAIL {$name}: expected ".($case['expected'] ? 'stale' : 'fresh')." got ".($actual ? 'stale' : 'fresh')."\n";
        $failed++;
    }
}

$state = [
    'profiles' => [
        ['id' => 'fi-a', 'alive' => true, 'scores' => ['24H' => 90]],
        ['id' => 'fi-b', 'alive' => true, 'scores' => ['24H' => 80]],
        ['id' => 'fi-c', 'alive' => true, 'scores' => ['24H' => 0]],
    ],
];
$top = p50_trigger_ranked_profile_ids($state, 10);
if ($top !== ['fi-a', 'fi-b']) {
    echo "FAIL ranked ids\n";
    $failed++;
}

$all = p50_trigger_syncable_profile_ids($state);
if ($all !== ['fi-a', 'fi-b', 'fi-c']) {
    echo "FAIL syncable ids\n";
    $failed++;
}

$existing = [
    'manualDataValidated' => true,
    'originalLinkValidatedAt' => gmdate('c'),
    'url' => 'https://example.com/a',
];
$incoming = [
    'originalLinkValidatedAt' => gmdate('c', time() - 3600),
    'url' => 'https://example.com/b',
];
if (p50_trigger_should_replace($existing, $incoming)) {
    echo "FAIL should keep fresh manual trigger\n";
    $failed++;
}

if ($failed > 0) {
    fwrite(STDERR, "trigger_sync_unit: {$failed} failure(s)\n");
    exit(1);
}

echo "trigger_sync_unit: ok\n";
