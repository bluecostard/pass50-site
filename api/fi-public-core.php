<?php
declare(strict_types=1);

/**
 * Helpers partagés pour les pages FI publiques (SEO).
 */

function p50_fi_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function p50_fi_clean(string $value, int $max = 220): string {
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_substr($value, 0, $max);
}

function p50_fi_base_url(): string {
    $configFile = __DIR__.'/config.php';
    if (is_file($configFile)) {
        try {
            $config = require $configFile;
            $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
            if (preg_match('#^https://#i', $base)) {
                return preg_replace('#^https://www\.#i', 'https://', $base) ?: $base;
            }
        } catch (Throwable) {
        }
    }
    return 'https://pass50.store';
}

function p50_fi_state(): array {
    static $loaded = false;
    static $state = [];
    if ($loaded) {
        return $state;
    }
    $loaded = true;
    $configFile = __DIR__.'/config.php';
    if (!is_file($configFile)) {
        return [];
    }
    try {
        $config = require $configFile;
        $d = $config['db'] ?? [];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($d['host'] ?? 'localhost'),
            (int)($d['port'] ?? 3306),
            (string)($d['name'] ?? ''),
            (string)($d['charset'] ?? 'utf8mb4')
        );
        $pdo = new PDO($dsn, (string)($d['user'] ?? ''), (string)($d['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $raw = $pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1")->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : [];
        $state = is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        error_log('PASS50 fi-public-core: '.$e->getMessage());
        $state = [];
    }
    return $state;
}

function p50_fi_profiles(array $state = []): array {
    if ($state === []) {
        $state = p50_fi_state();
    }
    $out = [];
    foreach ((array)($state['profiles'] ?? []) as $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $id = trim((string)($profile['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        if (array_key_exists('alive', $profile) && empty($profile['alive'])) {
            continue;
        }
        $out[] = $profile;
    }
    return $out;
}

function p50_fi_profile_by_id(string $id, array $state = []): ?array {
    $id = trim($id);
    if ($id === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/', $id)) {
        return null;
    }
    foreach (p50_fi_profiles($state) as $profile) {
        if ((string)($profile['id'] ?? '') === $id) {
            return $profile;
        }
    }
    return null;
}

function p50_fi_public_photo(array $profile): string {
    if (($profile['photoStatus'] ?? '') !== 'validated') {
        return '';
    }
    $url = trim((string)($profile['photoUrl'] ?? ''));
    if ($url === '') {
        $url = trim((string)($profile['photoCandidateUrl'] ?? ''));
    }
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/')) {
        return $url;
    }
    return '';
}

function p50_fi_score(array $profile, string $period = '24H'): float {
    $scores = is_array($profile['scores'] ?? null) ? $profile['scores'] : [];
    return (float)($scores[$period] ?? $profile['score'] ?? 0);
}

/** @return array{rank: ?int, total: int} */
function p50_fi_rank_24h(array $profile, array $state = []): array {
    $profiles = p50_fi_profiles($state);
    $ranked = array_values(array_filter(
        $profiles,
        static function (array $p): bool {
            if (array_key_exists('eligible', $p) && empty($p['eligible'])) {
                return false;
            }
            if (array_key_exists('classable', $p) && $p['classable'] === false) {
                return false;
            }
            return p50_fi_score($p, '24H') > 0;
        }
    ));
    usort($ranked, static function (array $a, array $b): int {
        $sa = p50_fi_score($a, '24H');
        $sb = p50_fi_score($b, '24H');
        if ($sa === $sb) {
            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        }
        return $sb <=> $sa;
    });
    $id = (string)($profile['id'] ?? '');
    foreach ($ranked as $i => $row) {
        if ((string)($row['id'] ?? '') === $id) {
            return ['rank' => $i + 1, 'total' => count($ranked)];
        }
    }
    return ['rank' => null, 'total' => count($ranked)];
}

function p50_fi_region_label(string $region): string {
    return match (strtoupper(trim($region))) {
        'CI' => 'Côte d’Ivoire',
        'DIASPORA' => 'Diaspora',
        'BOTH' => 'Côte d’Ivoire & diaspora',
        default => $region !== '' ? $region : 'Afrique',
    };
}

function p50_fi_official_links(array $profile): array {
    $links = is_array($profile['links'] ?? null) ? $profile['links'] : [];
    $out = [];
    foreach (['Instagram', 'TikTok', 'YouTube', 'Facebook', 'X', 'Snapchat', 'LinkedIn'] as $platform) {
        $url = trim((string)($links[$platform] ?? ''));
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $out[] = ['platform' => $platform, 'url' => $url];
        }
    }
    return $out;
}

function p50_fi_canonical(string $id): string {
    return rtrim(p50_fi_base_url(), '/').'/fi/'.rawurlencode($id);
}

function p50_fi_indexable(array $profile): bool {
    $name = trim((string)($profile['name'] ?? ''));
    if ($name === '') {
        return false;
    }
    if (array_key_exists('alive', $profile) && empty($profile['alive'])) {
        return false;
    }
    return true;
}
