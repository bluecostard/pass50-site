<?php
declare(strict_types=1);

/**
 * PASS50 Live Scout — moteur de détection indépendant.
 * Ne dépend pas de live-radar-v4-*.php ni de p50_live_streams.
 */

require_once __DIR__.'/http-tools.php';
require_once __DIR__.'/data-engine-core.php';

const P50_SCOUT_PLATFORMS = ['Facebook', 'TikTok', 'Instagram', 'YouTube'];
const P50_SCOUT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const P50_SCOUT_MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const P50_SCOUT_OFFICIAL_STATUSES = ['verified', 'owner_verified', 'manual_verified', 'ok', 'blocked_but_exists'];

function p50_scout_unescape(string $value): string {
    return html_entity_decode(str_replace(['\\u0026', '\\u003d', '\\/'], ['&', '=', '/'], $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function p50_scout_blocked(string $body): bool {
    return (bool)preg_match('/captcha|verify to continue|security check|challenge-platform|access denied|temporarily blocked|unusual traffic|robot check/i', $body);
}

function p50_scout_viewers(string $body): ?int {
    foreach ([
        '/"concurrentViewers"\s*:\s*"?(\d+)"?/i',
        '/"user_count"\s*:\s*"?(\d+)"?/i',
        '/"viewerCount"\s*:\s*"?(\d+)"?/i',
        '/"liveRoomUserCount"\s*:\s*"?(\d+)"?/i',
        '/"viewCount"\s*:\s*"?(\d+)"?/i',
    ] as $pattern) {
        if (preg_match($pattern, $body, $m)) return (int)$m[1];
    }
    return null;
}

function p50_scout_official_url_override(string $profileId, string $platform, string $url): string {
    $key = strtolower(trim($profileId)).'|'.strtolower(trim($platform));
    $overrides = [
        'apoutchou|tiktok' => 'https://www.tiktok.com/@apoutchou_national1',
        'general-camille-makosso|tiktok' => 'https://www.tiktok.com/@generalmakossocamille79',
    ];
    return $overrides[$key] ?? $url;
}

function p50_scout_identity(string $platform, string $url): array {
    $parts = parse_url(trim($url));
    $path = (string)($parts['path'] ?? '');
    $handle = '';
    $profileUrl = rtrim(trim($url), '/');
    $liveUrl = $profileUrl;

    if ($platform === 'TikTok' && preg_match('#/@([A-Za-z0-9._-]+)#', $path, $m)) {
        $handle = $m[1];
        $aliases = [
            'generalmakossocamille1' => 'generalmakossocamille79',
            'generalcamillemakosso' => 'generalmakossocamille79',
            'apoutchou.225' => 'apoutchou_national1',
            'apoutchounational' => 'apoutchou_national1',
        ];
        $handle = $aliases[strtolower($handle)] ?? $handle;
        $profileUrl = 'https://www.tiktok.com/@'.$handle;
        $liveUrl = $profileUrl.'/live';
    } elseif ($platform === 'Instagram' && preg_match('#^/([A-Za-z0-9._-]+)/?#', $path, $m)) {
        $handle = $m[1];
        $profileUrl = 'https://www.instagram.com/'.$handle.'/';
        $liveUrl = $profileUrl.'live/';
    } elseif ($platform === 'YouTube') {
        if (preg_match('#/(?:@([^/]+)|channel/([^/]+)|c/([^/]+)|user/([^/]+))#i', $path, $m)) {
            $handle = $m[1] ?: ($m[2] ?: ($m[3] ?: ($m[4] ?? '')));
        }
        $liveUrl = preg_replace('#/(live)?/?$#', '', $profileUrl).'/live';
        if (preg_match('#/(watch|shorts)/#i', $path) || str_contains(strtolower((string)($parts['host'] ?? '')), 'youtu.be')) {
            $liveUrl = $profileUrl;
        }
    } elseif ($platform === 'Facebook') {
        if (preg_match('#facebook\.com/([A-Za-z0-9._-]+)#i', $url, $m) && !in_array(strtolower($m[1]), ['watch', 'groups', 'events', 'reel', 'reels', 'share'], true)) {
            $handle = $m[1];
            $profileUrl = 'https://www.facebook.com/'.$handle;
            $liveUrl = $profileUrl.'/live';
        }
    }

    return ['handle' => $handle, 'profileUrl' => $profileUrl, 'liveUrl' => $liveUrl];
}

function p50_scout_is_direct_url(string $platform, string $url): bool {
    $url = trim($url);
    if ($url === '' || p50_platform($url) !== $platform) return false;
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    return match ($platform) {
        'TikTok' => (bool)preg_match('#/@[A-Za-z0-9._-]+(?:/live)?/?$#', $path),
        'YouTube' => !preg_match('#/(results|search)(?:/|$)#i', $path)
            && (bool)preg_match('#/(?:@[^/]+|channel/[^/]+|c/[^/]+|user/[^/]+|watch|live|shorts)(?:/|$)#i', $path),
        'Instagram' => (bool)preg_match('#^/[A-Za-z0-9._-]+/?$#', $path)
            && !preg_match('#^/(explore|accounts|reels?|stories|direct|developer|about|privacy|legal)(?:/|$)#i', $path),
        'Facebook' => !preg_match('#^/(login|watch|groups|marketplace|gaming|events|reels?|share|sharer)(?:/|$)#i', $path)
            && trim($path, '/') !== '',
        default => false,
    };
}

/** Catalogue des comptes officiels à surveiller (indépendant du radar V4). */
function p50_scout_catalog(array $platforms = [], string $q = '', int $limit = 400): array {
    p50_de_ensure_schema();
    $platforms = array_values(array_intersect(P50_SCOUT_PLATFORMS, $platforms ?: P50_SCOUT_PLATFORMS));
    if (!$platforms) $platforms = P50_SCOUT_PLATFORMS;

    $placeholders = implode(',', array_fill(0, count($platforms), '?'));
    $statusPlaceholders = implode(',', array_fill(0, count(P50_SCOUT_OFFICIAL_STATUSES), '?'));
    $params = array_merge($platforms, P50_SCOUT_OFFICIAL_STATUSES, [55]);

    $sql = "SELECT r.profile_id, r.public_name, r.handle, s.platform, s.normalized_url AS url, s.confidence, s.status
            FROM p50_profile_registry r
            JOIN p50_social_links s ON s.profile_id = r.profile_id
            WHERE r.alive = 1
              AND s.platform IN ($placeholders)
              AND s.status IN ($statusPlaceholders)
              AND s.confidence >= ?";
    if ($q !== '') {
        $sql .= ' AND (r.public_name LIKE ? OR r.handle LIKE ? OR r.profile_id LIKE ? OR s.normalized_url LIKE ?)';
        $like = '%'.$q.'%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY r.public_name ASC, FIELD(s.platform,\'TikTok\',\'Facebook\',\'Instagram\',\'YouTube\') LIMIT '.(int)max(1, min(800, $limit));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];
    $targets = [];
    foreach ($rows as $row) {
        $platform = (string)$row['platform'];
        $url = p50_scout_official_url_override((string)$row['profile_id'], $platform, trim((string)$row['url']));
        if (!p50_scout_is_direct_url($platform, $url)) continue;
        $identity = p50_scout_identity($platform, $url);
        $targets[] = [
            'id' => hash('sha1', $row['profile_id'].'|'.$platform.'|'.$url),
            'profileId' => (string)$row['profile_id'],
            'name' => (string)$row['public_name'],
            'handle' => (string)($row['handle'] ?: ($identity['handle'] ? '@'.$identity['handle'] : '')),
            'platform' => $platform,
            'url' => $url,
            'profileUrl' => $identity['profileUrl'],
            'liveUrl' => $identity['liveUrl'],
            'confidence' => (int)$row['confidence'],
            'status' => (string)$row['status'],
        ];
    }
    return $targets;
}

function p50_scout_probe_jobs(array $target): array {
    $platform = (string)$target['platform'];
    $identity = p50_scout_identity($platform, (string)$target['url']);
    $handle = rawurlencode((string)$identity['handle']);

    if ($platform === 'YouTube') {
        $live = (string)$identity['liveUrl'];
        return $live !== '' ? ['channel_live' => ['url' => $live, 'accept' => 'text/html,*/*;q=0.7']] : [];
    }

    if ($platform === 'TikTok' && $identity['handle'] !== '') {
        $apiHeaders = [
            'Referer: '.$identity['profileUrl'],
            'Origin: https://www.tiktok.com',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
        ];
        return [
            'room_api' => [
                'url' => 'https://www.tiktok.com/api-live/user/room/?aid=1988&sourceType=54&uniqueId='.$handle,
                'accept' => 'application/json,text/plain,*/*',
                'headers' => $apiHeaders,
            ],
            'live_page' => [
                'url' => $identity['liveUrl'].'?lang=fr',
                'accept' => 'text/html,application/xhtml+xml,*/*;q=0.7',
                'headers' => ['Referer: '.$identity['profileUrl']],
            ],
            'mobile_live' => [
                'url' => 'https://m.tiktok.com/@'.$handle.'/live',
                'accept' => 'text/html,application/xhtml+xml,*/*;q=0.7',
                'userAgent' => P50_SCOUT_MOBILE_UA,
            ],
        ];
    }

    if ($platform === 'Instagram' && $identity['handle'] !== '') {
        return [
            'web_profile' => [
                'url' => 'https://www.instagram.com/api/v1/users/web_profile_info/?username='.$handle,
                'accept' => 'application/json,text/plain,*/*',
                'headers' => [
                    'X-IG-App-ID: 936619743392459',
                    'X-Requested-With: XMLHttpRequest',
                    'Referer: '.$identity['profileUrl'],
                    'Origin: https://www.instagram.com',
                ],
            ],
            'profile_html' => [
                'url' => $identity['profileUrl'].'?hl=fr',
                'accept' => 'text/html,application/xhtml+xml,*/*;q=0.7',
            ],
        ];
    }

    if ($platform === 'Facebook') {
        $profile = rtrim($identity['profileUrl'], '/');
        return [
            'live_path' => ['url' => $identity['liveUrl'], 'accept' => 'text/html,application/xhtml+xml,*/*;q=0.7'],
            'videos' => ['url' => $profile.'/videos/', 'accept' => 'text/html,application/xhtml+xml,*/*;q=0.7'],
            'mobile' => [
                'url' => str_replace('www.facebook.com', 'm.facebook.com', $identity['profileUrl']),
                'accept' => 'text/html,application/xhtml+xml,*/*;q=0.7',
                'userAgent' => P50_SCOUT_MOBILE_UA,
            ],
        ];
    }

    return [];
}

function p50_scout_site_headers(string $url): array {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if (str_contains($host, 'tiktok.com')) return ['referer' => 'https://www.tiktok.com/', 'origin' => 'https://www.tiktok.com'];
    if (str_contains($host, 'instagram.com')) return ['referer' => 'https://www.instagram.com/', 'origin' => 'https://www.instagram.com'];
    if (str_contains($host, 'facebook.com')) return ['referer' => 'https://www.facebook.com/', 'origin' => 'https://www.facebook.com'];
    if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) return ['referer' => 'https://www.youtube.com/', 'origin' => 'https://www.youtube.com'];
    return ['referer' => 'https://www.google.com/', 'origin' => ''];
}

function p50_scout_fetch_parallel(array $jobs, int $timeout = 8): array {
    if (!$jobs) return [];
    $multi = curl_multi_init();
    $handles = [];
    $results = [];
    if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) @curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 16);

    foreach ($jobs as $jobId => $job) {
        $url = (string)$job['url'];
        if (!p50_public_http_url($url)) {
            $results[$jobId] = ['ok' => false, 'status' => 0, 'body' => '', 'finalUrl' => $url, 'error' => 'invalid_url', 'timeMs' => 0];
            continue;
        }
        $accept = (string)($job['accept'] ?? 'text/html,*/*;q=0.7');
        $isJson = stripos($accept, 'application/json') !== false;
        $site = p50_scout_site_headers($url);
        $headers = [
            'Accept: '.$accept,
            'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Referer: '.$site['referer'],
            'sec-ch-ua: "Chromium";v="126", "Google Chrome";v="126", "Not.A/Brand";v="99"',
            'Sec-Fetch-Dest: '.($isJson ? 'empty' : 'document'),
            'Sec-Fetch-Mode: '.($isJson ? 'cors' : 'navigate'),
            'Sec-Fetch-Site: '.($isJson ? 'same-origin' : 'none'),
            'Upgrade-Insecure-Requests: 1',
        ];
        if ($site['origin'] !== '' && $isJson) $headers[] = 'Origin: '.$site['origin'];
        if (!empty($job['headers']) && is_array($job['headers'])) {
            foreach ($job['headers'] as $header) {
                $header = trim((string)$header);
                if ($header === '') continue;
                $name = strtok($header, ':');
                if ($name !== false) {
                    $headers = array_values(array_filter($headers, static fn($existing) => stripos($existing, $name.':') !== 0));
                }
                $headers[] = $header;
            }
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_USERAGENT => (string)($job['userAgent'] ?? P50_SCOUT_UA),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
        ]);
        $handles[$jobId] = ['ch' => $ch, 'started' => microtime(true)];
        curl_multi_add_handle($multi, $ch);
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 0.4);
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $jobId => $pack) {
        $ch = $pack['ch'];
        $body = curl_multi_getcontent($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $ok = $errno === 0 && is_string($body) && $statusCode >= 200 && $statusCode < 400;
        $results[$jobId] = [
            'ok' => $ok,
            'status' => $statusCode,
            'body' => is_string($body) ? $body : '',
            'finalUrl' => $finalUrl ?: (string)($jobs[$jobId]['url'] ?? ''),
            'error' => $ok ? '' : ($error !== '' ? $error : ('http_'.$statusCode)),
            'timeMs' => (int)round((microtime(true) - $pack['started']) * 1000),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $results;
}

function p50_scout_detect_youtube(array $target, array $responses): array {
    $r = $responses['channel_live'] ?? [];
    $html = (string)($r['body'] ?? '');
    $ms = (int)($r['timeMs'] ?? 0);
    if (empty($r['ok']) || $html === '') {
        return p50_scout_hit($target, 'unknown', 0, '', null, $ms, (string)($r['error'] ?? 'youtube_unreachable'), $responses);
    }
    if (p50_scout_blocked($html)) {
        return p50_scout_hit($target, 'unknown', 0, '', null, $ms, 'youtube_blocked', $responses);
    }

    $ended = (bool)preg_match('/"(?:endTimestamp|actualEndTime)"\s*:\s*"[^"]+"/i', $html)
        || (bool)preg_match('/itemprop=["\']endDate["\']/i', $html);
    $isLive = (bool)preg_match('/"isLiveNow"\s*:\s*true/i', $html)
        || (bool)preg_match('/itemprop=["\']isLiveBroadcast["\'][^>]+content=["\']True["\']/i', $html)
        || ((bool)preg_match('/"isLiveContent"\s*:\s*true/i', $html)
            && (bool)preg_match('/"playabilityStatus"\s*:\s*\{[^}]*"status"\s*:\s*"OK"/is', $html));

    $base = (string)($r['finalUrl'] ?? $target['url']);
    $meta = p50_page_metadata($html, $base);
    $videoId = '';
    if (preg_match('#/(?:live|watch|shorts|embed)/([A-Za-z0-9_-]{6,})#', $base, $m)) $videoId = $m[1];
    if ($videoId === '' && preg_match('/[?&]v=([A-Za-z0-9_-]{6,})/', $base, $m)) $videoId = $m[1];
    if ($videoId === '' && preg_match('/"videoId"\s*:\s*"([A-Za-z0-9_-]{6,})"/', $html, $m)) $videoId = $m[1];
    $url = $videoId !== '' ? 'https://www.youtube.com/watch?v='.$videoId : ((string)($meta['canonical'] ?: $base));
    $title = trim((string)($meta['title'] ?? ''));
    $title = preg_replace('/\s*-\s*YouTube\s*$/iu', '', $title) ?? $title;
    if ($title === '') $title = trim((string)($target['name'] ?? 'YouTube')).' — direct';

    if ($ended && !$isLive) {
        return p50_scout_hit($target, 'replay', 95, $title, $url, $ms, 'youtube_replay', $responses, [
            'thumbnail' => (string)($meta['image'] ?? ''),
            'videoId' => $videoId,
        ]);
    }
    if (!$isLive) {
        return p50_scout_hit($target, 'offline', 94, $title, $url, $ms, 'youtube_not_live', $responses);
    }
    return p50_scout_hit($target, 'live', 98, $title, $url, $ms, '', $responses, [
        'thumbnail' => (string)(($meta['image'] ?? '') ?: ($videoId !== '' ? 'https://i.ytimg.com/vi/'.rawurlencode($videoId).'/hqdefault.jpg' : '')),
        'viewers' => p50_scout_viewers($html),
        'videoId' => $videoId,
    ]);
}

function p50_scout_detect_tiktok(array $target, array $responses): array {
    $identity = p50_scout_identity('TikTok', (string)$target['url']);
    $maxMs = 0;
    $readable = 0;
    $blocked = 0;
    $roomId = '';
    $apiLive = false;
    $htmlLive = false;
    $ended = false;
    $bodies = [];

    foreach ($responses as $label => $r) {
        $maxMs = max($maxMs, (int)($r['timeMs'] ?? 0));
        if (empty($r['ok'])) continue;
        $body = p50_scout_unescape((string)($r['body'] ?? ''));
        if ($body === '' || p50_scout_blocked($body)) {
            $blocked++;
            continue;
        }
        $readable++;
        $bodies[$label] = $body;
        if (preg_match('/"roomId"\s*:\s*"?([1-9]\d{5,})"?/i', $body, $m)
            || preg_match('/"room_id"\s*:\s*"?([1-9]\d{5,})"?/i', $body, $m)
            || preg_match('/"liveRoomId"\s*:\s*"?([1-9]\d{5,})"?/i', $body, $m)) {
            $roomId = (string)$m[1];
        }
        if ($label === 'room_api') {
            $apiLive = (bool)preg_match('/"status"\s*:\s*2(?:\D|$)/i', $body)
                || ($roomId !== '' && (bool)preg_match('/"LiveRoom"\s*:|"webcastRoomId"\s*:/i', $body));
            if ((bool)preg_match('/"status"\s*:\s*4(?:\D|$)/i', $body)) $ended = true;
        }
        if (preg_match('/"(?:isLive|is_live)"\s*:\s*true/i', $body)
            || preg_match('/"(?:liveStatus|live_status)"\s*:\s*2(?:\D|$)/i', $body)) {
            $htmlLive = true;
        }
        if (preg_match('/\b(?:live (?:est |is |has )?(?:termin|ended|finished)|n[\'’]est plus en direct|not currently live)\b/iu', $body)) {
            $ended = true;
        }
    }

    $title = trim((string)($target['name'] ?? ''));
    if ($title === '') $title = 'TikTok';
    $title .= ' est en direct';
    $url = $identity['liveUrl'];
    $metaBody = (string)($bodies['live_page'] ?? $bodies['mobile_live'] ?? $bodies['room_api'] ?? '');
    $meta = p50_page_metadata($metaBody, $url);

    if ($apiLive || ($htmlLive && $roomId !== '')) {
        $confidence = $apiLive && $htmlLive ? 97 : ($apiLive ? 92 : 78);
        $state = $confidence >= 90 ? 'live' : 'probable';
        return p50_scout_hit($target, $state, $confidence, $title, $url, $maxMs, $state === 'probable' ? 'tiktok_probable' : '', $responses, [
            'thumbnail' => (string)($meta['image'] ?? ''),
            'viewers' => p50_scout_viewers(implode("\n", $bodies)),
            'roomId' => $roomId,
        ]);
    }
    if ($ended) {
        return p50_scout_hit($target, 'offline', 96, $title, $url, $maxMs, 'tiktok_ended', $responses);
    }
    if ($readable > 0) {
        return p50_scout_hit($target, 'offline', 88, $title, $url, $maxMs, 'tiktok_no_signal', $responses);
    }
    return p50_scout_hit($target, 'unknown', 0, $title, $url, $maxMs, $blocked > 0 ? 'tiktok_blocked' : 'tiktok_unreachable', $responses);
}

function p50_scout_detect_instagram(array $target, array $responses): array {
    $identity = p50_scout_identity('Instagram', (string)$target['url']);
    $maxMs = 0;
    $combined = '';
    $readable = 0;
    $blocked = 0;
    foreach ($responses as $r) {
        $maxMs = max($maxMs, (int)($r['timeMs'] ?? 0));
        if (empty($r['ok'])) continue;
        $body = (string)($r['body'] ?? '');
        if ($body === '' || p50_scout_blocked($body)) {
            $blocked++;
            continue;
        }
        $readable++;
        $combined .= "\n".$body;
    }
    $title = trim((string)($target['name'] ?? 'Instagram')).' est en direct';
    $url = $identity['profileUrl'];
    if ($readable === 0) {
        return p50_scout_hit($target, 'unknown', 0, $title, $url, $maxMs, $blocked > 0 ? 'instagram_blocked' : 'instagram_unreachable', $responses);
    }
    $live = (bool)preg_match('/"(?:is_live_broadcast|isLiveBroadcast|is_live|isLive|broadcasting_content)"\s*:\s*true/i', $combined)
        || (bool)preg_match('/"broadcast_status"\s*:\s*"(?:active|live)"/i', $combined)
        || (bool)preg_match('/"live_broadcast_id"\s*:\s*"?[1-9]\d{5,}"?/i', $combined)
        || (bool)preg_match('/"media_product_type"\s*:\s*"LIVE"/i', $combined);
    $offline = (bool)preg_match('/"(?:is_live_broadcast|isLiveBroadcast|is_live|isLive)"\s*:\s*false/i', $combined)
        || (bool)preg_match('/"broadcast_status"\s*:\s*"(?:ended|archived|vod|finished)"/i', $combined);
    if ($live) {
        $meta = p50_page_metadata($combined, $url);
        return p50_scout_hit($target, 'live', 94, $title, $url, $maxMs, '', $responses, [
            'thumbnail' => (string)($meta['image'] ?? ''),
            'viewers' => p50_scout_viewers($combined),
        ]);
    }
    if ($offline) {
        return p50_scout_hit($target, 'offline', 94, $title, $url, $maxMs, 'instagram_offline', $responses);
    }
    return p50_scout_hit($target, 'offline', 86, $title, $url, $maxMs, 'instagram_no_signal', $responses);
}

function p50_scout_detect_facebook(array $target, array $responses): array {
    $identity = p50_scout_identity('Facebook', (string)$target['url']);
    $maxMs = 0;
    $readable = 0;
    $blocked = 0;
    $videoId = '';
    $structured = false;
    $textLive = false;
    $ended = false;
    $bodies = [];

    foreach ($responses as $label => $r) {
        $maxMs = max($maxMs, (int)($r['timeMs'] ?? 0));
        if (empty($r['ok'])) continue;
        $body = p50_scout_unescape((string)($r['body'] ?? ''));
        $probe = $body."\n".(string)($r['finalUrl'] ?? '');
        if ($body === '' || p50_scout_blocked($probe)) {
            $blocked++;
            continue;
        }
        $readable++;
        $bodies[$label] = $body;
        if (preg_match('/"(?:is_live_streaming|isLiveStreaming|is_live|isLive)"\s*:\s*true|"broadcast_status"\s*:\s*"(?:LIVE|ACTIVE)"/i', $probe)) {
            $structured = true;
        }
        if (preg_match('/\b(?:est\s+en\s+direct|en\s+direct\s+maintenant|is\s+live(?:\s+now)?|currently\s+live|live\s+now)\b/iu', $probe)) {
            $textLive = true;
        }
        if (preg_match('/"(?:is_live_streaming|isLiveStreaming|is_live|isLive)"\s*:\s*false|"broadcast_status"\s*:\s*"(?:VOD|ENDED|FINISHED)"|\bwas live\b|\bétait en direct\b/iu', $probe)) {
            $ended = true;
        }
        if (preg_match('#facebook\.com/(?:watch/\?v=|[^"\'<>\s]+/videos/)([1-9]\d{5,})#i', $probe, $m)
            || preg_match('/"(?:video_id|videoId|broadcast_id)"\s*:\s*"?([1-9]\d{5,})"?/i', $probe, $m)) {
            $videoId = (string)$m[1];
        }
    }

    $title = trim((string)($target['name'] ?? 'Facebook')).' est en direct';
    $url = $videoId !== '' ? 'https://www.facebook.com/watch/?v='.rawurlencode($videoId) : $identity['liveUrl'];
    if ($readable === 0) {
        return p50_scout_hit($target, 'unknown', 0, $title, $url, $maxMs, $blocked > 0 ? 'facebook_blocked' : 'facebook_unreachable', $responses);
    }
    if (($structured || $textLive) && $videoId !== '') {
        $meta = p50_page_metadata(implode("\n", $bodies), $url);
        return p50_scout_hit($target, 'live', $structured ? 95 : 88, $title, $url, $maxMs, '', $responses, [
            'thumbnail' => (string)($meta['image'] ?? ''),
            'viewers' => p50_scout_viewers(implode("\n", $bodies)),
            'videoId' => $videoId,
        ]);
    }
    if ($structured || $textLive) {
        return p50_scout_hit($target, 'probable', 72, $title, $identity['liveUrl'], $maxMs, 'facebook_probable', $responses);
    }
    if ($ended) {
        return p50_scout_hit($target, 'offline', 94, $title, $url, $maxMs, 'facebook_ended', $responses);
    }
    return p50_scout_hit($target, 'offline', 85, $title, $url, $maxMs, 'facebook_no_signal', $responses);
}

function p50_scout_hit(array $target, string $state, int $confidence, string $title, ?string $url, int $ms, string $error, array $responses, array $extra = []): array {
    $probes = [];
    foreach ($responses as $label => $r) {
        $probes[$label] = [
            'ok' => (bool)($r['ok'] ?? false),
            'status' => (int)($r['status'] ?? 0),
            'timeMs' => (int)($r['timeMs'] ?? 0),
            'error' => (string)($r['error'] ?? ''),
        ];
    }
    return [
        'targetId' => (string)($target['id'] ?? ''),
        'profileId' => (string)($target['profileId'] ?? ''),
        'name' => (string)($target['name'] ?? ''),
        'handle' => (string)($target['handle'] ?? ''),
        'platform' => (string)($target['platform'] ?? ''),
        'state' => $state,
        'confidence' => $confidence,
        'title' => $title,
        'url' => $url ?: (string)($target['liveUrl'] ?? $target['url'] ?? ''),
        'officialUrl' => (string)($target['url'] ?? ''),
        'thumbnail' => (string)($extra['thumbnail'] ?? ''),
        'viewers' => $extra['viewers'] ?? null,
        'responseMs' => $ms,
        'error' => $error,
        'checkedAt' => gmdate(DATE_ATOM),
        'probes' => $probes,
        'meta' => array_diff_key($extra, ['thumbnail' => 1, 'viewers' => 1]),
    ];
}

function p50_scout_detect(array $target, array $responses): array {
    return match ((string)$target['platform']) {
        'YouTube' => p50_scout_detect_youtube($target, $responses),
        'TikTok' => p50_scout_detect_tiktok($target, $responses),
        'Instagram' => p50_scout_detect_instagram($target, $responses),
        'Facebook' => p50_scout_detect_facebook($target, $responses),
        default => p50_scout_hit($target, 'unknown', 0, '', null, 0, 'unsupported_platform', $responses),
    };
}

function p50_scout_scan_targets(array $targets): array {
    $jobs = [];
    $groups = [];
    foreach ($targets as $index => $target) {
        foreach (p50_scout_probe_jobs($target) as $label => $job) {
            $jobId = $index.'|'.$label;
            $jobs[$jobId] = $job;
            $groups[$index][$label] = $jobId;
        }
    }
    $raw = p50_scout_fetch_parallel($jobs, 7);
    $hits = [];
    foreach ($targets as $index => $target) {
        $responses = [];
        foreach ((array)($groups[$index] ?? []) as $label => $jobId) {
            $responses[$label] = $raw[$jobId] ?? ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'missing', 'timeMs' => 0];
        }
        $hits[] = p50_scout_detect($target, $responses);
    }
    return $hits;
}

function p50_scout_target_from_url(string $url, string $name = '', string $profileId = ''): ?array {
    $url = trim($url);
    $platform = p50_platform($url);
    if (!in_array($platform, P50_SCOUT_PLATFORMS, true)) return null;
    $identity = p50_scout_identity($platform, $url);
    return [
        'id' => hash('sha1', 'manual|'.$platform.'|'.$url),
        'profileId' => $profileId !== '' ? $profileId : 'manual',
        'name' => $name !== '' ? $name : ($identity['handle'] !== '' ? $identity['handle'] : $platform),
        'handle' => $identity['handle'] !== '' ? '@'.$identity['handle'] : '',
        'platform' => $platform,
        'url' => $url,
        'profileUrl' => $identity['profileUrl'],
        'liveUrl' => $identity['liveUrl'],
        'confidence' => 0,
        'status' => 'manual',
    ];
}

function p50_scout_summarize(array $hits): array {
    $summary = [
        'scanned' => count($hits),
        'live' => 0,
        'probable' => 0,
        'replay' => 0,
        'offline' => 0,
        'unknown' => 0,
        'byPlatform' => [],
    ];
    foreach (P50_SCOUT_PLATFORMS as $platform) {
        $summary['byPlatform'][$platform] = ['scanned' => 0, 'live' => 0, 'probable' => 0, 'offline' => 0, 'unknown' => 0, 'replay' => 0];
    }
    foreach ($hits as $hit) {
        $state = (string)($hit['state'] ?? 'unknown');
        $platform = (string)($hit['platform'] ?? '');
        if (isset($summary[$state])) $summary[$state]++;
        else $summary['unknown']++;
        if (!isset($summary['byPlatform'][$platform])) continue;
        $summary['byPlatform'][$platform]['scanned']++;
        if (isset($summary['byPlatform'][$platform][$state])) $summary['byPlatform'][$platform][$state]++;
        else $summary['byPlatform'][$platform]['unknown']++;
    }
    return $summary;
}
