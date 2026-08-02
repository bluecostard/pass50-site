<?php
declare(strict_types=1);

/**
 * PASS50 Live Trust Gate (balanced)
 * - Public : confirmation live positive encore dans une fenêtre réaliste (> intervalle de balayage)
 * - Anti-fantômes : unknown / offline / replay ne maintiennent PAS la liste publique
 * - Détection : ne pas exiger une reconfirmation toutes les 90s (sinon liste vide)
 */
const P50_LIVE_V4_TRUST_REVISION = 'LIVE-PUBLISH-UTC-2026-08-03-1';

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
 * Âge max depuis la dernière confirmation live positive pour rester visible.
 * Doit rester > intervalle quick/full sweep, sinon la liste se vide en permanence.
 */
const P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS = [
    'TikTok' => 720,      // 12 min
    'YouTube' => 1200,    // 20 min
    'Instagram' => 900,   // 15 min
    'Facebook' => 900,    // 15 min
];

/** Grâce serveur pour retester un direct sans le clôturer trop tôt ( ≥ fenêtre publique ). */
const P50_LIVE_V4_RECONFIRM_GRACE_MINUTES = [
    'TikTok' => 18,
    'YouTube' => 25,
    'Instagram' => 20,
    'Facebook' => 20,
];

function p50_live_v4_public_max_age(string $platform): int {
    return max(120, (int)(P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS[$platform] ?? 900));
}

function p50_live_v4_reconfirm_grace_minutes(string $platform): int {
    return max(5, (int)(P50_LIVE_V4_RECONFIRM_GRACE_MINUTES[$platform] ?? 18));
}

function p50_live_v4_trust_seconds_map(): array {
    return P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS;
}

function p50_live_v4_reconfirm_grace_map(): array {
    return P50_LIVE_V4_RECONFIRM_GRACE_MINUTES;
}

/** Un flux n’est publiable que s’il a une confirmation live positive encore fraîche. */
function p50_live_v4_is_publicly_fresh(array $row, ?int $now = null): bool {
    $now ??= time();
    $platform = (string)($row['platform'] ?? '');
    $source = (string)($row['source'] ?? 'automatic');
    $status = (string)($row['status'] ?? '');
    if ($status !== 'live') return false;

    $seen = p50_live_v4_parse_utc((string)($row['last_seen_at'] ?? $row['lastSeenAt'] ?? ''));
    if ($seen === null) return false;

    if ($source === 'meta_authorized') {
        return ($now - $seen) <= 20 * 60;
    }

    $state = strtolower((string)($row['last_state'] ?? $row['lastCheckState'] ?? ''));
    if ($state !== 'live') return false;

    return ($now - $seen) <= p50_live_v4_public_max_age($platform);
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
