<?php
declare(strict_types=1);

require __DIR__ . '/../api/scoring-15c-core.php';

function scoring_assert(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$weights = p50_15c_weights();
scoring_assert(abs(array_sum($weights) - 1.0) < 0.0001, 'Les poids 15C doivent totaliser 100 %.');

$raw = p50_15c_build_raw(
    100000,
    500000,
    12000,
    3000,
    800,
    400,
    2.5,
    3.0,
    [1200.0, 2400.0, 3600.0],
    [['v' => 90000, 't' => 1000], ['v' => 100000, 't' => 2000]],
    1.2
);

scoring_assert($raw['c3'] !== null && $raw['c3'] > 0, 'c3 croissance abonnés doit être calculé.');
scoring_assert($raw['c9'] !== null, 'c9 volume partages doit être calculé.');
scoring_assert($raw['c10'] !== null, 'c10 likes doit être calculé.');
scoring_assert($raw['c11'] !== null, 'c11 saves doit être calculé.');
scoring_assert($raw['c12'] !== null, 'c12 live doit être calculé.');
scoring_assert($raw['c15'] !== null, 'c15 accélération doit être calculé.');
scoring_assert(!isset($raw['c15']) || $raw['c15'] !== $raw['c9'], 'c15 ne doit pas dupliquer c9.');

$scored = p50_15c_score_raw($raw);
scoring_assert($scored['coverage'] >= 99.0, 'Tous les critères actifs doivent couvrir ~100 %.');
scoring_assert(count($scored['scores']) === 15, 'Les 15 critères doivent produire un score chacun.');

echo "scoring_15c_unit: OK\n";
