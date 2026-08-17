<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/intelligence-signals-core.php';

function observed_must(bool $ok, string $message): void {
    if ($ok) {
        return;
    }
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

$state = [
    'profiles' => [
        ['id' => 'census-ahou-lafricaine', 'name' => "Ahou L’Africaine", 'alive' => true, 'scores' => ['24H' => 71], 'links' => ['TikTok' => 'https://www.tiktok.com/@ahou']],
        ['id' => 'census-african-ryou', 'name' => 'African Ryou', 'alive' => true, 'scores' => ['24H' => 88], 'links' => ['YouTube' => 'https://www.youtube.com/@african_ryou']],
        ['id' => 'census-axel-tresor', 'name' => 'Axel Trésor', 'alive' => true, 'scores' => ['24H' => 0], 'links' => ['TikTok' => 'https://www.tiktok.com/@axel']],
        ['id' => 'census-aziz-47', 'name' => 'Aziz 47', 'alive' => true, 'scores' => ['24H' => 0], 'links' => ['YouTube' => 'https://www.youtube.com/@aziz']],
    ],
];
$ranking = p50_is_public_ranking_index($state, '24H');
observed_must(($ranking['census-african-ryou']['rank'] ?? 0) === 1, 'African Ryou doit mener le 24H.');
observed_must(($ranking['census-ahou-lafricaine']['rank'] ?? 0) === 2, 'Ahou L’Africaine doit être 2e sur le score public.');
observed_must(!isset($ranking['census-axel-tresor']), 'Axel Trésor à 0 ne doit pas entrer au classement Intelligence.');

$empty = ['fresh' => true, 'sufficientData' => false, 'buzzIndex' => 0, 'growthIndex' => 0, 'globalVariation' => 0.0];
$scraped = [[
    'status' => 'validated', 'platforms' => ['TikTok'], 'signalScore' => 59,
    'occurredAt' => gmdate(DATE_ATOM), 'sourceType' => 'activity', 'eventType' => 'video', 'title' => "c'est dohi",
]];
$axel = p50_is_profile_aggregate($empty + ['name' => 'Axel Trésor'], $scraped, [
    'officialPlatforms' => ['TikTok'], 'publicRank' => 0, 'publicScore' => 0, 'publicPeriod' => '24H',
]);
observed_must($axel['combinedBuzzIndex'] === 0 && $axel['signalScore'] === 0 && $axel['priorityScore'] === 0, 'Un post collecté ne doit pas inventer un buzz si le score public est 0.');
observed_must($axel['classification'] === 'building', 'Axel Trésor non classé doit rester à construire.');
observed_must($axel['recentSignals'] === [], 'Le post brut ne doit pas s’afficher comme preuve confirmée.');
observed_must(in_array('TikTok', $axel['signalPlatforms'], true), 'Le compte officiel TikTok doit rester visible.');

$aziz = p50_is_profile_aggregate($empty + ['name' => 'Aziz 47'], [[
    'status' => 'validated', 'platforms' => ['YouTube'], 'signalScore' => 59,
    'occurredAt' => gmdate(DATE_ATOM), 'sourceType' => 'activity', 'eventType' => 'video', 'title' => 'Aziz | Episode 1 - YouTube',
]], [
    'officialPlatforms' => ['YouTube'], 'publicRank' => 0, 'publicScore' => 0, 'publicPeriod' => '24H',
]);
observed_must($aziz['combinedBuzzIndex'] === 0 && $aziz['combinedGrowthIndex'] === 0 && $aziz['signalScore'] === 0, 'Aziz 47 non classé ne doit pas hériter d’un buzz 23/37/57.');
observed_must($aziz['priorityScore'] === 0 && $aziz['classification'] === 'building', 'Aziz 47 à score 0 doit rester à construire.');
observed_must($aziz['recentSignals'] === [], 'Le scrape YouTube d’Aziz ne doit pas passer pour un signal confirmé.');
observed_must(in_array('YouTube', $aziz['signalPlatforms'], true), 'Le compte officiel YouTube d’Aziz doit rester visible.');

$ahou = p50_is_profile_aggregate($empty + ['name' => "Ahou L’Africaine"], [], [
    'officialPlatforms' => ['TikTok'], 'publicRank' => 11, 'publicScore' => 71, 'publicPeriod' => '24H',
]);
observed_must($ahou['combinedBuzzIndex'] === 71, 'Le buzz d’Ahou doit reprendre le score public, pas 0.');
observed_must($ahou['publicRank'] === 11, 'Le rang public d’Ahou doit être affiché.');
observed_must($ahou['classification'] === 'confirmed_buzz', 'Une 11e place publique ne doit pas tomber dans à construire.');

$ryou = p50_is_profile_aggregate($empty + ['name' => 'African Ryou'], [[
    'status' => 'validated', 'platforms' => ['TikTok'], 'signalScore' => 80,
    'occurredAt' => gmdate(DATE_ATOM), 'sourceType' => 'live_radar', 'eventType' => 'live', 'title' => 'Live TikTok',
]], [
    'officialPlatforms' => ['TikTok', 'YouTube'], 'publicRank' => 8, 'publicScore' => 88, 'publicPeriod' => '24H',
]);
observed_must($ryou['hasLive'] === true, 'Le live radar d’African Ryou doit être reconnu.');
observed_must($ryou['combinedBuzzIndex'] >= 88, 'African Ryou doit garder au moins son score public.');
observed_must($ryou['classification'] === 'confirmed_buzz', 'African Ryou Top 10 + live doit sortir de à construire.');

echo "Intelligence observed ranking/radar: OK\n";
