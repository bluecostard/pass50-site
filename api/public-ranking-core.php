<?php
declare(strict_types=1);

/**
 * PASS50 Public Ranking API — contrat app V1
 *
 * Lecture publique slim pour desktop + futurs clients mobiles.
 * Source de vérité toujours app_state public ; ce snapshot est dérivé à la publication.
 */
const P50_PUBLIC_RANKING_CONTRACT = 'PASS50-PUBLIC-RANKING-V1';
const P50_PUBLIC_RANKING_STATE_ID = 'public_ranking';
const P50_PUBLIC_RANKING_LIMIT = 50;
const P50_PUBLIC_RANKING_PERIODS = ['2H', '24H', '48H', '7J', '15J'];

function p50_public_ranking_photo_url(array $profile): string {
    $status = strtolower(trim((string)($profile['photoStatus'] ?? '')));
    if (!in_array($status, ['validated', 'verified', 'approved', 'manual_verified'], true)) {
        return '';
    }
    $url = trim((string)(($profile['photoUrl'] ?? '') ?: ($profile['photoCandidateUrl'] ?? '')));
    return $url;
}

function p50_public_ranking_scores(array $profile): array {
    $scores = [];
    $raw = is_array($profile['scores'] ?? null) ? $profile['scores'] : [];
    foreach (P50_PUBLIC_RANKING_PERIODS as $period) {
        $value = $raw[$period] ?? null;
        if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
            $scores[$period] = 0.0;
            continue;
        }
        $scores[$period] = round((float)$value, 1);
    }
    return $scores;
}

function p50_public_ranking_row(array $profile, int $rank, string $period): array {
    $scores = p50_public_ranking_scores($profile);
    $badges = array_values(array_filter(array_map('strval', (array)($profile['badges'] ?? []))));
    return [
        'rank' => $rank,
        'id' => (string)($profile['id'] ?? ''),
        'name' => (string)($profile['name'] ?? ''),
        'handle' => (string)($profile['handle'] ?? ''),
        'initials' => (string)($profile['initials'] ?? ''),
        'category' => (string)($profile['category'] ?? ''),
        'region' => (string)($profile['region'] ?? ''),
        'score' => (float)($scores[$period] ?? 0),
        'scores' => $scores,
        'delta' => (int)($profile['delta'] ?? 0),
        'badges' => array_slice($badges, 0, 3),
        'photoUrl' => p50_public_ranking_photo_url($profile),
        'photoStatus' => (string)($profile['photoStatus'] ?? 'missing'),
        'photoPosition' => (string)(($profile['photoPosition'] ?? '') ?: '50% 50%'),
    ];
}

/** Construit le snapshot slim (Top 50 × 5 périodes) depuis l’état public. */
function p50_public_ranking_build(array $state, array $meta = []): array {
    $tombstoned = function_exists('p50_tombstone_ids')
        ? array_fill_keys(p50_tombstone_ids($state), true)
        : [];
    $profiles = [];
    foreach ((array)($state['profiles'] ?? []) as $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $id = trim((string)($profile['id'] ?? ''));
        if ($id === '' || isset($tombstoned[$id])) {
            continue;
        }
        if (array_key_exists('alive', $profile) && empty($profile['alive'])) {
            continue;
        }
        $profiles[$id] = $profile;
    }

    $periods = [];
    foreach (P50_PUBLIC_RANKING_PERIODS as $period) {
        $rankable = [];
        foreach ($profiles as $id => $profile) {
            $score = $profile['scores'][$period] ?? null;
            if (!is_int($score) && !is_float($score) && !is_numeric($score)) {
                continue;
            }
            if ((float)$score <= 0) {
                continue;
            }
            $rankable[] = $profile;
        }
        usort($rankable, static function (array $a, array $b) use ($period): int {
            $diff = (float)($b['scores'][$period] ?? 0) <=> (float)($a['scores'][$period] ?? 0);
            if ($diff !== 0) {
                return $diff;
            }
            $name = strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            if ($name !== 0) {
                return $name;
            }
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });
        $rows = [];
        foreach (array_slice($rankable, 0, P50_PUBLIC_RANKING_LIMIT) as $index => $profile) {
            $rows[] = p50_public_ranking_row($profile, $index + 1, $period);
        }
        $periods[$period] = $rows;
    }

    $rankingMeta = is_array($state['metricsRankingMeta'] ?? null) ? $state['metricsRankingMeta'] : [];
    return [
        'ok' => true,
        'contract' => P50_PUBLIC_RANKING_CONTRACT,
        'stateRevision' => (int)($state['stateRevision'] ?? ($meta['stateRevision'] ?? 0)),
        'runUuid' => (string)(($meta['runUuid'] ?? '') ?: ($rankingMeta['runUuid'] ?? '')),
        'algorithmVersion' => (string)(($meta['algorithmVersion'] ?? '') ?: ($rankingMeta['algorithmVersion'] ?? '')),
        'publishedAt' => (string)(($meta['publishedAt'] ?? '')
            ?: ($rankingMeta['publishedAt'] ?? '')
            ?: ($state['publishedAt'] ?? '')
            ?: gmdate('c')),
        'limit' => P50_PUBLIC_RANKING_LIMIT,
        'periods' => $periods,
        'generatedAt' => gmdate('c'),
    ];
}

function p50_public_ranking_persist(PDO $pdo, array $snapshot): void {
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Snapshot classement public invalide.');
    }
    $stmt = $pdo->prepare(
        "INSERT INTO app_state(id,data,updated_by) VALUES(?,?,NULL)
         ON DUPLICATE KEY UPDATE data=VALUES(data),updated_by=NULL,updated_at=NOW()"
    );
    $stmt->execute([P50_PUBLIC_RANKING_STATE_ID, $json]);
}

function p50_public_ranking_load(PDO $pdo): ?array {
    $stmt = $pdo->prepare("SELECT data FROM app_state WHERE id=? LIMIT 1");
    $stmt->execute([P50_PUBLIC_RANKING_STATE_ID]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['periods']) || !is_array($data['periods'])) {
        return null;
    }
    $data['ok'] = true;
    $data['contract'] = P50_PUBLIC_RANKING_CONTRACT;
    $data['cached'] = true;
    return $data;
}

/**
 * Lecture publique : snapshot persisté, sinon construction éphémère (sans écriture).
 */
function p50_public_ranking_response(PDO $pdo): array {
    $cached = p50_public_ranking_load($pdo);
    if ($cached !== null) {
        return $cached;
    }
    if (!function_exists('p50_de_load_public_state')) {
        require_once __DIR__ . '/data-engine-core.php';
    }
    if (!function_exists('p50_tombstone_ids') && is_file(__DIR__ . '/profile-tombstone-core.php')) {
        require_once __DIR__ . '/profile-tombstone-core.php';
    }
    $state = p50_de_load_public_state();
    if (!$state) {
        return [
            'ok' => true,
            'contract' => P50_PUBLIC_RANKING_CONTRACT,
            'stateRevision' => 0,
            'runUuid' => '',
            'algorithmVersion' => '',
            'publishedAt' => gmdate('c'),
            'limit' => P50_PUBLIC_RANKING_LIMIT,
            'periods' => array_fill_keys(P50_PUBLIC_RANKING_PERIODS, []),
            'generatedAt' => gmdate('c'),
            'cached' => false,
            'ephemeral' => true,
        ];
    }
    $snapshot = p50_public_ranking_build($state);
    $snapshot['cached'] = false;
    $snapshot['ephemeral'] = true;
    return $snapshot;
}

function p50_public_ranking_rebuild_and_persist(PDO $pdo, array $meta = []): array {
    if (!function_exists('p50_de_load_public_state')) {
        require_once __DIR__ . '/data-engine-core.php';
    }
    if (!function_exists('p50_tombstone_ids') && is_file(__DIR__ . '/profile-tombstone-core.php')) {
        require_once __DIR__ . '/profile-tombstone-core.php';
    }
    $state = p50_de_load_public_state();
    if (!$state) {
        throw new RuntimeException('État public introuvable.');
    }
    $snapshot = p50_public_ranking_build($state, $meta);
    p50_public_ranking_persist($pdo, $snapshot);
    $snapshot['cached'] = true;
    $snapshot['ephemeral'] = false;
    return $snapshot;
}
