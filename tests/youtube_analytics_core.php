<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/youtube-analytics-core.php';

function p50ya_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$payload = [
    'columnHeaders' => [
        ['name' => 'shares', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
        ['name' => 'views', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
        ['name' => 'averageViewDuration', 'columnType' => 'METRIC', 'dataType' => 'FLOAT'],
        ['name' => 'estimatedMinutesWatched', 'columnType' => 'METRIC', 'dataType' => 'FLOAT'],
        ['name' => 'subscribersLost', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
        ['name' => 'subscribersGained', 'columnType' => 'METRIC', 'dataType' => 'INTEGER'],
    ],
    'rows' => [[12, 3456, 92.5, 780.25, 3, 11]],
];
$parsed = p50ya_parse_report($payload);
p50ya_assert($parsed['hasData'] === true, 'Le rapport devrait être exploitable.');
p50ya_assert($parsed['metrics']['views'] === 3456, 'Les vues sont mal normalisées.');
p50ya_assert(abs($parsed['metrics']['averageViewDuration'] - 92.5) < 0.001, 'La durée moyenne est incorrecte.');
p50ya_assert($parsed['metrics']['netSubscribers'] === 8, 'Le solde d’abonnés est incorrect.');

$zero = p50ya_parse_report([
    'columnHeaders' => [['name' => 'views'], ['name' => 'likes']],
    'rows' => [[0, 0]],
]);
p50ya_assert($zero['hasData'] === true, 'Un zéro explicitement mesuré ne doit pas devenir une donnée absente.');
p50ya_assert($zero['metrics']['views'] === 0, 'Une métrique nulle explicite doit rester zéro.');

$empty = p50ya_parse_report(['columnHeaders' => [['name' => 'views']], 'rows' => []]);
p50ya_assert($empty['hasData'] === false, 'Un rapport sans ligne doit rester sans donnée.');
p50ya_assert($empty['metrics']['views'] === null, 'Une métrique absente ne doit pas devenir zéro.');

$range = p50ya_date_range(28, new DateTimeImmutable('2026-07-29 12:00:00', new DateTimeZone('UTC')));
p50ya_assert($range['startDate'] === '2026-07-01', 'La date de début est incorrecte.');
p50ya_assert($range['endDate'] === '2026-07-28', 'La date de fin est incorrecte.');
p50ya_assert(p50ya_days(12) === 28, 'Une période non autorisée doit revenir à 28 jours.');

echo "YouTube Analytics core: OK\n";
