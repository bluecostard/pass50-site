<?php
declare(strict_types=1);

/**
 * PASS50 Live Trust Gate
 * Garantit qu’un LIVE public est encore réellement en cours.
 * Sépare la publication stricte (ce que voit l’utilisateur) de la grâce
 * de reconfirmation serveur (anti faux négatifs sur blocage réseau).
 */
const P50_LIVE_V4_TRUST_REVISION = 'LIVE-TRUST-GATE-2026-08-03-1';

/** Âge max depuis la dernière confirmation live positive pour rester visible. */
const P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS = [
    'TikTok' => 90,
    'YouTube' => 240,
    'Instagram' => 120,
    'Facebook' => 120,
];

/** Grâce serveur pour retester un direct sans le clôturer trop tôt. */
const P50_LIVE_V4_RECONFIRM_GRACE_MINUTES = [
    'TikTok' => 8,
    'YouTube' => 15,
    'Instagram' => 10,
    'Facebook' => 10,
];

function p50_live_v4_public_max_age(string $platform): int {
    return max(30, (int)(P50_LIVE_V4_PUBLIC_MAX_AGE_SECONDS[$platform] ?? 120));
}

function p50_live_v4_reconfirm_grace_minutes(string $platform): int {
    return max(1, (int)(P50_LIVE_V4_RECONFIRM_GRACE_MINUTES[$platform] ?? 10));
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

    $seenRaw = (string)($row['last_seen_at'] ?? $row['lastSeenAt'] ?? '');
    $seen = $seenRaw !== '' ? strtotime($seenRaw) : false;
    if ($seen === false) return false;

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
