<?php
declare(strict_types=1);

/**
 * PASS50 Live Trust Gate
 * - Publication : preuve forte (TikTok API status 2 + room, YouTube isLiveNow)
 * - Un live TikTok/YouTube détecté reste public jusqu’à une preuve de fin
 *   (replay, tiktok_live_ended, dismiss). Pas de limite de temps.
 * - Instagram / Facebook gardent une fenêtre courte (sondes HTML).
 */
const P50_LIVE_V4_TRUST_REVISION = 'LIVE-DETECTED-STAYS-2026-08-22-1';

/** Parse une datetime MySQL/ISO en timestamp Unix (UTC). */
function p50_live_v4_parse_utc(?string $value): ?int {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $value)) {
        try {
            return (new DateTimeImmutable(str_replace(' ', 'T', $value), new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
    try {
        return (new DateTimeImmutable($value))->getTimestamp();
    } catch (Throwable) {
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }
}

/**
 * 0 = pas de limite de temps : le live reste public jusqu’à une preuve de fin.
 * Instagram / Facebook conservent une fenêtre (HTML peut confirmer une fin).
 */
const P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS = [
    'TikTok' => 0,
    'YouTube' => 0,
    'Instagram' => 600,
    'Facebook' => 600,
];

/** 0 = ne jamais unconfirm par âge. IG/FB seulement. */
const P50_LIVE_V4_RECONFIRM_GRACE_MINUTES = [
    'TikTok' => 0,
    'YouTube' => 0,
    'Instagram' => 15,
    'Facebook' => 15,
];

function p50_live_v4_public_max_age(string $platform): int {
    if (!array_key_exists($platform, P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS)) {
        return 900;
    }
    return max(0, (int)P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS[$platform]);
}

function p50_live_v4_reconfirm_grace_minutes(string $platform): int {
    if (!array_key_exists($platform, P50_LIVE_V4_RECONFIRM_GRACE_MINUTES)) {
        return 18;
    }
    return max(0, (int)P50_LIVE_V4_RECONFIRM_GRACE_MINUTES[$platform]);
}

/** TikTok / YouTube automatiques : un live détecté n’expire pas. */
function p50_live_v4_detected_live_has_no_time_limit(string $platform, string $source = 'automatic'): bool {
    if ($source !== 'automatic') {
        return false;
    }
    $platform = strtolower(trim($platform));
    return $platform === 'tiktok' || $platform === 'youtube';
}

function p50_live_v4_trust_seconds_map(): array {
    return P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS;
}

function p50_live_v4_reconfirm_grace_map(): array {
    return P50_LIVE_V4_RECONFIRM_GRACE_MINUTES;
}

/** Un flux n’est publiable que s’il est live et n’a pas de preuve de fin. */
function p50_live_v4_is_publicly_fresh(array $row, ?int $now = null): bool {
    $now ??= time();
    $platform = (string)($row['platform'] ?? '');
    $source = (string)($row['source'] ?? 'automatic');
    $status = (string)($row['status'] ?? '');
    if ($status !== 'live') return false;

    $state = strtolower((string)($row['last_state'] ?? $row['lastCheckState'] ?? ''));

    if ($source === 'meta_authorized') {
        $seen = p50_live_v4_parse_utc((string)($row['last_seen_at'] ?? $row['lastSeenAt'] ?? ''));
        if ($seen === null) return false;
        return ($now - $seen) <= 20 * 60;
    }

    if (p50_live_v4_detected_live_has_no_time_limit($platform, $source)) {
        return $state !== 'replay';
    }

    if (in_array($state, ['offline', 'replay'], true)) return false;

    $seen = p50_live_v4_parse_utc((string)($row['last_seen_at'] ?? $row['lastSeenAt'] ?? ''));
    if ($seen === null) return false;
    if ($state !== '' && $state !== 'live') return false;

    $maxAge = p50_live_v4_public_max_age($platform);
    if ($maxAge <= 0) return true;
    return ($now - $seen) <= $maxAge;
}

function p50_live_v4_filter_public_streams(array $streams): array {
    $now = time();
    $out = [];
    foreach ($streams as $stream) {
        if (!is_array($stream)) continue;
        $row = $stream;
        if (!isset($row['last_state']) && isset($row['lastCheckState'])) {
            $row['last_state'] = $row['lastCheckState'];
        }
        if (!isset($row['last_seen_at']) && isset($row['lastSeenAt'])) {
            $row['last_seen_at'] = $row['lastSeenAt'];
        }
        if (!p50_live_v4_is_publicly_fresh($row, $now)) continue;
        $stream['trust'] = [
            'gate' => P50_LIVE_V4_TRUST_REVISION,
            'maxAgeSeconds' => p50_live_v4_public_max_age((string)($stream['platform'] ?? '')),
            'fresh' => true,
        ];
        $out[] = $stream;
    }
    return $out;
}
