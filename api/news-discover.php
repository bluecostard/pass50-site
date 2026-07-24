<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/http-tools.php';
$user = auth_user();
require_role($user, 'owner', 'admin');
require_method('POST');

$in = json_input();
$name = trim((string)($in['name'] ?? ''));
$days = max(1, min(90, (int)($in['days'] ?? 15)));
if ($name === '') json_response(['error' => 'Nom de l’influenceur requis.'], 422);

function p50_news_article(array $row): ?array {
    $url = trim((string)($row['url'] ?? ''));
    $title = trim((string)($row['title'] ?? ''));
    if ($url === '' || $title === '' || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    return [
        'title' => html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'url' => $url,
        'image' => trim((string)($row['image'] ?? $row['socialimage'] ?? '')),
        'domain' => trim((string)($row['domain'] ?? $host)),
        'date' => trim((string)($row['date'] ?? $row['seendate'] ?? '')),
        'language' => trim((string)($row['language'] ?? 'fr')),
        'source' => trim((string)($row['source'] ?? '')),
    ];
}

function p50_news_dedupe(array $articles): array {
    $seen = []; $out = [];
    foreach ($articles as $a) {
        if (!is_array($a)) continue;
        $key = strtolower(trim((string)($a['url'] ?? '')));
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true; $out[] = $a;
        if (count($out) >= 30) break;
    }
    return $out;
}

$articles = [];
$warnings = [];
$source = '';

try {
    $query = '"' . str_replace('"', '', $name) . '"';
    $gdelt = 'https://api.gdeltproject.org/api/v2/doc/doc?query=' . rawurlencode($query) . '&mode=ArtList&maxrecords=30&format=json&sort=datedesc&timespan=' . $days . 'd';
    $r = p50_http_fetch($gdelt, 18, 'application/json,*/*;q=0.7');
    if ($r['ok']) {
        $data = json_decode($r['body'], true);
        foreach ((array)($data['articles'] ?? []) as $row) {
            $a = p50_news_article(is_array($row) ? $row : []);
            if ($a) { $a['source'] = 'GDELT'; $articles[] = $a; }
        }
        if ($articles) $source = 'GDELT';
    } else {
        $warnings[] = 'GDELT indisponible (HTTP ' . (int)$r['status'] . ').';
    }
} catch (Throwable $e) {
    $warnings[] = 'GDELT indisponible.';
}

if (!$articles) {
    try {
        $rssUrl = 'https://news.google.com/rss/search?q=' . rawurlencode('"' . $name . '" when:' . $days . 'd') . '&hl=fr&gl=CI&ceid=CI:fr';
        $r = p50_http_fetch($rssUrl, 18, 'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.5');
        if ($r['ok'] && function_exists('simplexml_load_string')) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($r['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    $title = trim((string)$item->title);
                    $url = trim((string)$item->link);
                    $pub = trim((string)$item->pubDate);
                    $sourceNode = $item->source ?? null;
                    $domain = $sourceNode ? trim((string)$sourceNode) : (string)(parse_url($url, PHP_URL_HOST) ?: 'Google News');
                    $date = $pub !== '' ? (new DateTimeImmutable($pub))->format('Y-m-d H:i') : '';
                    $a = p50_news_article(['title'=>$title,'url'=>$url,'domain'=>$domain,'date'=>$date,'language'=>'fr','source'=>'Google News']);
                    if ($a) $articles[] = $a;
                }
                if ($articles) $source = 'Google News';
            }
        } else {
            $warnings[] = 'Google News indisponible.';
        }
    } catch (Throwable $e) {
        $warnings[] = 'Google News indisponible.';
    }
}

$articles = p50_news_dedupe($articles);
json_response([
    'ok' => true,
    'name' => $name,
    'days' => $days,
    'source' => $source ?: 'Aucune source',
    'articles' => $articles,
    'warning' => implode(' ', array_unique($warnings)),
    'message' => $articles ? count($articles) . ' résultat(s) trouvé(s).' : 'Aucun article récent trouvé pour cette période.',
]);
