<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$user = auth_user();
require_role($user, 'owner', 'admin');
$in = json_input();

$profileId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($in['profileId'] ?? '')) ?: '';
$name = trim((string)($in['name'] ?? ''));
$handle = trim((string)($in['handle'] ?? ''));
$officialUrls = array_values(array_filter((array)($in['officialUrls'] ?? []), static fn($v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL)));
if ($profileId === '' || $name === '') json_response(['error' => 'Profil incomplet.'], 422);

function media_http_get(string $url, int $timeout = 12): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'PASS50-MediaCollector/1.0 (+https://pass50.store)',
        CURLOPT_HTTPHEADER => ['Accept: application/json,text/html;q=0.9,*/*;q=0.7'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
}

function media_json_get(string $url): array {
    $body = media_http_get($url);
    if ($body === null) return [];
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

function media_norm(string $value): string {
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted)) $value = $converted;
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
    return trim(preg_replace('/\s+/', ' ', $value) ?: '');
}

function media_name_score(string $haystack, string $name, string $handle = ''): int {
    $h = media_norm($haystack);
    $n = media_norm($name);
    $score = 0;
    if ($n !== '' && str_contains($h, $n)) $score += 65;
    $tokens = array_values(array_filter(explode(' ', $n), static fn($t) => strlen($t) >= 3));
    $matched = 0;
    foreach ($tokens as $token) if (str_contains($h, $token)) $matched++;
    if ($matched >= 2) $score = max($score, 50);
    elseif ($matched === 1) $score = max($score, 22);
    $hn = media_norm(ltrim($handle, '@'));
    if ($hn !== '' && strlen($hn) >= 4 && str_contains($h, $hn)) $score += 25;
    return min(100, $score);
}

function media_candidate_key(string $url): string { return hash('sha256', $url); }

$candidates = [];
$seen = [];
$addCandidate = static function(array $candidate) use (&$candidates, &$seen): void {
    $url = (string)($candidate['url'] ?? '');
    if (!filter_var($url, FILTER_VALIDATE_URL)) return;
    $key = media_candidate_key($url);
    if (isset($seen[$key])) return;
    $seen[$key] = true;
    $candidate['id'] = substr($key, 0, 16);
    $candidate['requiresHumanConfirmation'] = true;
    $candidate['autoDownloadAllowed'] = false;
    $candidates[] = $candidate;
};

// 1) Wikipédia francophone : uniquement des pages dont le titre ou les métadonnées correspondent au nom.
$wpUrl = 'https://fr.wikipedia.org/w/api.php?' . http_build_query([
    'action' => 'query',
    'generator' => 'search',
    'gsrsearch' => '"' . $name . '"',
    'gsrnamespace' => 0,
    'gsrlimit' => 8,
    'prop' => 'pageimages|info|extracts',
    'piprop' => 'thumbnail',
    'pithumbsize' => 900,
    'inprop' => 'url',
    'exintro' => 1,
    'explaintext' => 1,
    'exsentences' => 2,
    'format' => 'json',
    'formatversion' => 2,
    'origin' => '*',
]);
$wp = media_json_get($wpUrl);
foreach (($wp['query']['pages'] ?? []) as $page) {
    $thumb = $page['thumbnail']['source'] ?? null;
    if (!$thumb) continue;
    $context = implode(' ', [(string)($page['title'] ?? ''), (string)($page['extract'] ?? '')]);
    $score = media_name_score($context, $name, $handle);
    if ($score < 50) continue;
    $addCandidate([
        'url' => $thumb,
        'previewUrl' => $thumb,
        'sourcePage' => $page['fullurl'] ?? 'https://fr.wikipedia.org/',
        'sourceName' => 'Wikipédia',
        'confidence' => $score >= 75 ? 'élevée' : 'moyenne',
        'confidenceScore' => $score,
        'reason' => 'Le nom du profil apparaît dans le titre ou la description de la page source.',
    ]);
}

// 2) Wikimedia Commons : on conserve uniquement les fichiers dont les métadonnées mentionnent clairement le nom.
$commonsUrl = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
    'action' => 'query',
    'generator' => 'search',
    'gsrsearch' => '"' . $name . '" filetype:bitmap',
    'gsrnamespace' => 6,
    'gsrlimit' => 12,
    'prop' => 'imageinfo|info',
    'iiprop' => 'url|size|mime|extmetadata',
    'iiurlwidth' => 900,
    'inprop' => 'url',
    'format' => 'json',
    'formatversion' => 2,
    'origin' => '*',
]);
$commons = media_json_get($commonsUrl);
foreach (($commons['query']['pages'] ?? []) as $page) {
    $info = $page['imageinfo'][0] ?? [];
    $thumb = $info['thumburl'] ?? $info['url'] ?? null;
    if (!$thumb) continue;
    $meta = $info['extmetadata'] ?? [];
    $contextParts = [(string)($page['title'] ?? '')];
    foreach (['ObjectName','ImageDescription','Categories','Credit','Artist'] as $field) {
        if (isset($meta[$field]['value'])) $contextParts[] = (string)$meta[$field]['value'];
    }
    $score = media_name_score(implode(' ', $contextParts), $name, $handle);
    if ($score < 55) continue;
    $addCandidate([
        'url' => $info['url'] ?? $thumb,
        'previewUrl' => $thumb,
        'sourcePage' => $page['fullurl'] ?? 'https://commons.wikimedia.org/',
        'sourceName' => 'Wikimedia Commons',
        'confidence' => $score >= 80 ? 'élevée' : 'moyenne',
        'confidenceScore' => $score,
        'reason' => 'Les métadonnées du fichier mentionnent clairement le nom du profil.',
    ]);
}

// 3) Pages officielles renseignées dans PASS50 : on lit uniquement leur image Open Graph.
foreach (array_slice($officialUrls, 0, 8) as $officialUrl) {
    $html = media_http_get($officialUrl, 10);
    if ($html === null) continue;
    $pageTitle = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $pageTitle = $m[1];
    $ogTitle = '';
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $html, $m)) $ogTitle = $m[1];
    $ogDesc = '';
    if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']/i', $html, $m)) $ogDesc = $m[1];
    $ogImage = '';
    if (preg_match('/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::secure_url)?["\']/i', $html, $m)) $ogImage = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($ogImage === '' || !filter_var($ogImage, FILTER_VALIDATE_URL)) continue;
    $score = media_name_score($pageTitle . ' ' . $ogTitle . ' ' . $ogDesc . ' ' . $officialUrl, $name, $handle);
    if ($score < 55) continue;
    $host = parse_url($officialUrl, PHP_URL_HOST) ?: 'Site officiel';
    $addCandidate([
        'url' => $ogImage,
        'previewUrl' => $ogImage,
        'sourcePage' => $officialUrl,
        'sourceName' => $host,
        'confidence' => $score >= 80 ? 'élevée' : 'moyenne',
        'confidenceScore' => $score,
        'reason' => 'La page officielle contient le nom ou l’identifiant du profil et expose cette image comme visuel principal.',
    ]);
}

usort($candidates, static fn($a, $b) => ($b['confidenceScore'] ?? 0) <=> ($a['confidenceScore'] ?? 0));
$candidates = array_slice($candidates, 0, 8);

json_response([
    'ok' => true,
    'profileId' => $profileId,
    'name' => $name,
    'candidates' => $candidates,
    'googleImagesUrl' => 'https://www.google.com/search?tbm=isch&q=' . rawurlencode($name . ' officiel portrait'),
    'rule' => 'Aucune image n’est téléchargée automatiquement. Si aucune photo ne représente clairement l’influenceur, le script ne télécharge rien.',
]);
