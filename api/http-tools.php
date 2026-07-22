<?php
declare(strict_types=1);

/** Outils réseau gratuits PASS50. Aucune clé payante requise. */
function p50_public_http_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) return false;
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || in_array($host, ['localhost','127.0.0.1','::1'], true)) return false;
    $ips = gethostbynamel($host) ?: [];
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

function p50_http_fetch(string $url, int $timeout = 15, string $accept = 'application/json,text/html;q=0.9,*/*;q=0.6', bool $head = false): array {
    if (!p50_public_http_url($url)) return ['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>$url,'contentType'=>'','error'=>'URL distante refusée'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(7, $timeout),
        CURLOPT_USERAGENT => 'PASS50-FreeTools/9.0 (+https://pass50.store)',
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'Accept-Language: fr-FR,fr;q=0.9,en;q=0.7'],
        CURLOPT_NOBODY => $head,
        CURLOPT_HEADER => false,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'ok' => is_string($body) && $status >= 200 && $status < 400,
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'finalUrl' => $finalUrl ?: $url,
        'contentType' => $contentType,
        'error' => $error,
    ];
}

function p50_json_get(string $url, int $timeout = 15): array {
    $r = p50_http_fetch($url, $timeout, 'application/json,*/*;q=0.7');
    if (!$r['ok']) return [];
    $data = json_decode($r['body'], true);
    return is_array($data) ? $data : [];
}

function p50_meta(string $html, string $name): string {
    $quoted = preg_quote($name, '/');
    $patterns = [
        '/<meta[^>]+(?:property|name)=["\']'.$quoted.'["\'][^>]+content=["\']([^"\']+)["\']/i',
        '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']'.$quoted.'["\']/i',
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern, $html, $m)) return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return '';
}

function p50_page_metadata(string $html, string $baseUrl): array {
    $title = p50_meta($html, 'og:title');
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $description = p50_meta($html, 'og:description') ?: p50_meta($html, 'description');
    $image = p50_meta($html, 'og:image:secure_url') ?: p50_meta($html, 'og:image') ?: p50_meta($html, 'twitter:image');
    $canonical = '';
    if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $html, $m)) $canonical = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (['image','canonical'] as $field) {
        $value = ${$field};
        if ($value !== '' && !preg_match('#^https?://#i', $value)) {
            $parts = parse_url($baseUrl);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            ${$field} = $origin . '/' . ltrim($value, '/');
        }
    }
    return compact('title','description','image','canonical');
}

function p50_platform(string $url): string {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) return 'YouTube';
    if (str_contains($host, 'tiktok.com')) return 'TikTok';
    if (str_contains($host, 'instagram.com')) return 'Instagram';
    if (str_contains($host, 'facebook.com') || str_contains($host, 'fb.watch')) return 'Facebook';
    if ($host === 'x.com' || str_ends_with($host, '.x.com') || str_contains($host, 'twitter.com')) return 'X';
    if (str_contains($host, 'linkedin.com')) return 'LinkedIn';
    if (str_contains($host, 'snapchat.com')) return 'Snapchat';
    return 'Web';
}

function p50_platform_host_ok(string $platform, string $url): bool {
    $detected = p50_platform($url);
    return $platform === 'Web' || strcasecmp($platform, $detected) === 0;
}

function p50_normalize_text(string $value): string {
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted)) $value = $converted;
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
    return trim(preg_replace('/\s+/', ' ', $value) ?: '');
}

function p50_name_score(string $haystack, string $name, string $handle = ''): int {
    $h = p50_normalize_text($haystack);
    $n = p50_normalize_text($name);
    $score = 0;
    if ($n !== '' && str_contains($h, $n)) $score += 65;
    $tokens = array_values(array_filter(explode(' ', $n), static fn($t) => strlen($t) >= 3));
    $matched = 0;
    foreach ($tokens as $token) if (str_contains($h, $token)) $matched++;
    if ($matched >= 2) $score = max($score, 52);
    elseif ($matched === 1) $score = max($score, 22);
    $hn = p50_normalize_text(ltrim($handle, '@'));
    if ($hn !== '' && strlen($hn) >= 4 && str_contains($h, $hn)) $score += 25;
    return min(100, $score);
}
