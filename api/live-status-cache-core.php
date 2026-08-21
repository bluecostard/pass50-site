<?php
declare(strict_types=1);

/**
 * Live status cache-only V1 — chemin rapide pour le public / clients app.
 * Évite load_public_state + sync registry + scans à chaque hit.
 */
const P50_LIVE_STATUS_CACHE_CONTRACT = 'PASS50-LIVE-STATUS-CACHE-V1';
const P50_LIVE_STATUS_CACHE_KEY = 'live_radar_v4_status_snapshot';
const P50_LIVE_STATUS_CACHE_TTL = 12; // secondes — une rebuild à la fois sous lock
const P50_LIVE_STATUS_CACHE_MAX_AGE = 10;
const P50_LIVE_STATUS_CACHE_SWR = 30;

function p50_live_status_cache_file(): string {
    return rtrim(sys_get_temp_dir(), '/') . '/pass50_live_status_v1.json';
}

function p50_live_status_cache_age(?array $payload): ?int {
    if (!is_array($payload)) {
        return null;
    }
    $generated = (string)($payload['generatedAt'] ?? '');
    if ($generated === '') {
        return null;
    }
    $ts = strtotime($generated);
    if ($ts === false) {
        return null;
    }
    return max(0, time() - $ts);
}

function p50_live_status_cache_load(): ?array {
    $file = p50_live_status_cache_file();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['ok']) && isset($decoded['liveStreams'])) {
                return $decoded;
            }
        }
    }
    if (!function_exists('p50_de_get_setting')) {
        return null;
    }
    $stored = p50_de_get_setting(P50_LIVE_STATUS_CACHE_KEY, null);
    if (is_array($stored) && !empty($stored['ok']) && isset($stored['liveStreams'])) {
        return $stored;
    }
    return null;
}

function p50_live_status_cache_invalidate(): void {
    @unlink(p50_live_status_cache_file());
    if (function_exists('p50_de_set_setting')) {
        try {
            p50_de_set_setting(P50_LIVE_STATUS_CACHE_KEY, ['ok' => false, 'invalidatedAt' => gmdate('c')]);
        } catch (Throwable) {
        }
    }
}

function p50_live_status_cache_store(array $payload): void {
    $payload['generatedAt'] = (string)($payload['generatedAt'] ?? gmdate('c'));
    $payload['contract'] = P50_LIVE_STATUS_CACHE_CONTRACT;
    $payload['cached'] = true;
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        return;
    }
    @file_put_contents(p50_live_status_cache_file(), $encoded, LOCK_EX);
    if (function_exists('p50_de_set_setting')) {
        try {
            p50_de_set_setting(P50_LIVE_STATUS_CACHE_KEY, $payload);
        } catch (Throwable) {
            // Best-effort : le fichier local suffit pour le worker courant.
        }
    }
}

/** Lives manuels sans décoder tout app_state. */
function p50_live_status_cache_manual_streams(): array {
    try {
        $raw = db()->query("SELECT JSON_EXTRACT(data, '$.liveStreams') FROM app_state WHERE id='public' LIMIT 1")->fetchColumn();
    } catch (Throwable) {
        return [];
    }
    if (!is_string($raw) || $raw === '' || $raw === 'null') {
        return [];
    }
    $lives = json_decode($raw, true);
    if (!is_array($lives)) {
        return [];
    }
    return p50_live_v4_manual_streams(['liveStreams' => $lives]);
}

function p50_live_status_cache_build(): array {
    $lastScan = '';
    if (function_exists('p50_de_get_setting')) {
        $lastScan = (string)p50_de_get_setting('live_radar_v4_last_scan_at', '');
    }
    $automatic = array_values(array_filter(
        p50_live_v4_active_rows(),
        static fn(array $stream): bool => !p50_live_v4_known_false_positive($stream)
    ));
    $manual = p50_live_status_cache_manual_streams();
    $streams = p50_live_v4_filter_public_streams(p50_live_v4_dedup($automatic, $manual));

    return [
        'ok' => true,
        'contract' => P50_LIVE_STATUS_CACHE_CONTRACT,
        'cached' => true,
        'liveStreams' => $streams,
        'radar' => [
            'version' => '4.6',
            'mode' => 'status',
            'scanPerformed' => false,
            'busy' => false,
            'forced' => false,
            'lastScanAt' => $lastScan !== '' ? $lastScan : null,
            'serverNow' => gmdate(DATE_ATOM),
            'cycleId' => null,
            'cycleComplete' => true,
            'cycleTotal' => 0,
            'cycleScanned' => 0,
            'sourcesScannedThisPass' => 0,
            'livesFoundThisPass' => 0,
            'candidatesFoundThisPass' => 0,
            'replaysFoundThisPass' => 0,
            'livesFoundInCycle' => 0,
            'candidatesFoundInCycle' => 0,
            'coveragePercent' => null,
            'passCoveragePercent' => null,
            'officialSourcesKnown' => null,
            'activeAutomaticConfirmed' => count($automatic),
            'refreshSeconds' => P50_LIVE_STATUS_CACHE_TTL,
            'trustGate' => defined('P50_LIVE_V4_TRUST_REVISION') ? P50_LIVE_V4_TRUST_REVISION : null,
            'diagnostics' => [],
            'cache' => [
                'ttlSeconds' => P50_LIVE_STATUS_CACHE_TTL,
                'maxAgeSeconds' => P50_LIVE_STATUS_CACHE_MAX_AGE,
                'staleWhileRevalidateSeconds' => P50_LIVE_STATUS_CACHE_SWR,
            ],
        ],
        'generatedAt' => gmdate('c'),
    ];
}

function p50_live_status_cache_headers(bool $hit): void {
    p50_public_edge_cache(P50_LIVE_STATUS_CACHE_MAX_AGE, P50_LIVE_STATUS_CACHE_SWR);
    header('X-PASS50-Live-Cache: ' . ($hit ? 'HIT' : 'MISS'));
    header('X-PASS50-Live-Contract: ' . P50_LIVE_STATUS_CACHE_CONTRACT);
}

function p50_live_status_cache_respond(): void {
    $cached = p50_live_status_cache_load();
    $age = p50_live_status_cache_age($cached);
    if ($cached !== null && $age !== null && $age <= P50_LIVE_STATUS_CACHE_TTL) {
        p50_live_status_cache_headers(true);
        json_response($cached);
    }

    $lock = false;
    try {
        $lock = (int)db()->query("SELECT GET_LOCK('pass50_live_status_cache', 0)")->fetchColumn() === 1;
    } catch (Throwable) {
        $lock = false;
    }

    if (!$lock) {
        // Un autre worker rebuild : servir le stale plutôt que de marteler MySQL.
        if ($cached !== null) {
            p50_live_status_cache_headers(true);
            json_response($cached);
        }
        // Pas de stale : rebuild court sans lock (premier cold start).
        $payload = p50_live_status_cache_build();
        p50_live_status_cache_store($payload);
        p50_live_status_cache_headers(false);
        json_response($payload);
    }

    try {
        // Re-check après acquisition du lock (double-checked).
        $cached = p50_live_status_cache_load();
        $age = p50_live_status_cache_age($cached);
        if ($cached !== null && $age !== null && $age <= P50_LIVE_STATUS_CACHE_TTL) {
            p50_live_status_cache_headers(true);
            json_response($cached);
        }
        $payload = p50_live_status_cache_build();
        p50_live_status_cache_store($payload);
        p50_live_status_cache_headers(false);
        json_response($payload);
    } finally {
        try {
            db()->query("SELECT RELEASE_LOCK('pass50_live_status_cache')");
        } catch (Throwable) {
        }
    }
}
