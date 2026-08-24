<?php
declare(strict_types=1);

require __DIR__.'/api/fi-public-core.php';

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=600');

$base = rtrim(p50_fi_base_url(), '/');
$today = gmdate('Y-m-d');

$static = [
    ['loc' => $base.'/', 'lastmod' => $today, 'changefreq' => 'hourly', 'priority' => '1.0'],
    ['loc' => $base.'/pronostics.html', 'lastmod' => $today, 'changefreq' => 'hourly', 'priority' => '0.9'],
    ['loc' => $base.'/telecharger.html', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.8'],
    ['loc' => $base.'/informations-legales.html', 'lastmod' => '2026-07-20', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ['loc' => $base.'/conditions-utilisation.html', 'lastmod' => '2026-07-29', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ['loc' => $base.'/politique-confidentialite.html', 'lastmod' => '2026-07-29', 'changefreq' => 'yearly', 'priority' => '0.3'],
    ['loc' => $base.'/terms.html', 'lastmod' => '2026-07-29', 'changefreq' => 'yearly', 'priority' => '0.2'],
    ['loc' => $base.'/privacy.html', 'lastmod' => '2026-07-29', 'changefreq' => 'yearly', 'priority' => '0.2'],
];

$profiles = p50_fi_profiles();
usort($profiles, static function (array $a, array $b): int {
    $sa = p50_fi_score($a, '24H');
    $sb = p50_fi_score($b, '24H');
    if ($sa === $sb) {
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    }
    return $sb <=> $sa;
});

$fiUrls = [];
foreach ($profiles as $profile) {
    if (!p50_fi_indexable($profile)) {
        continue;
    }
    $id = (string)($profile['id'] ?? '');
    if ($id === '') {
        continue;
    }
    $priority = p50_fi_score($profile, '24H') > 0 ? '0.7' : '0.5';
    $fiUrls[] = [
        'loc' => $base.'/fi/'.rawurlencode($id),
        'lastmod' => $today,
        'changefreq' => 'daily',
        'priority' => $priority,
    ];
}

$urls = array_merge($static, $fiUrls);

echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
foreach ($urls as $url) {
    echo "  <url>\n";
    echo '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8')."</loc>\n";
    echo '    <lastmod>'.htmlspecialchars($url['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8')."</lastmod>\n";
    echo '    <changefreq>'.htmlspecialchars($url['changefreq'], ENT_XML1 | ENT_QUOTES, 'UTF-8')."</changefreq>\n";
    echo '    <priority>'.htmlspecialchars($url['priority'], ENT_XML1 | ENT_QUOTES, 'UTF-8')."</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
