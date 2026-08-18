<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-schema-core.php';

const P50_TRIGGER_SYNC_VERSION = 'PASS50-TRIGGER-SYNC-V1.3';
const P50_TRIGGER_AUTO_MAX_AGE_SECONDS = 168 * 3600;
const P50_TRIGGER_MANUAL_MAX_AGE_SECONDS = 7 * 86400;

function p50_trigger_load_public_state(PDO $pdo): ?array {
    $stmt = $pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1 FOR UPDATE");
    $raw = $stmt ? $stmt->fetchColumn() : false;
    if ($raw === false || $raw === null || $raw === '') {
        return null;
    }
    $state = json_decode((string) $raw, true);
    return is_array($state) ? $state : null;
}

function p50_trigger_event_is_stale(array $event): bool {
    $validatedAt = strtotime((string) ($event['originalLinkValidatedAt'] ?? ''));
    $age = $validatedAt ? time() - $validatedAt : PHP_INT_MAX;

    if (!empty($event['autoSynced'])) {
        return $age > P50_TRIGGER_AUTO_MAX_AGE_SECONDS;
    }
    if (!empty($event['manualDataValidated'])) {
        return $age > P50_TRIGGER_MANUAL_MAX_AGE_SECONDS;
    }
    if ($validatedAt && $age > 24 * 3600) {
        return true;
    }
    $label = strtolower((string) ($event['publishedLabel'] ?? ''));
    if (empty($event['autoSynced']) && empty($event['manualDataValidated'])
        && preg_match('/\b3\s*j|\b72\s*h|trois jour|il y a 3/u', $label) === 1) {
        return true;
    }

    return false;
}

function p50_trigger_ranked_profile_ids(array $state, int $limit = 10): array {
    $profiles = array_values(array_filter(
        (array) ($state['profiles'] ?? []),
        static fn($profile) => is_array($profile)
            && !empty($profile['id'])
            && ($profile['alive'] ?? true)
            && empty($profile['adminDeleted'])
    ));
    usort($profiles, static function (array $a, array $b): int {
        $scoresA = is_array($a['scores'] ?? null) ? $a['scores'] : [];
        $scoresB = is_array($b['scores'] ?? null) ? $b['scores'] : [];
        $scoreA = (float) ($scoresA['24H'] ?? $scoresA['24h'] ?? 0);
        $scoreB = (float) ($scoresB['24H'] ?? $scoresB['24h'] ?? 0);

        return $scoreB <=> $scoreA;
    });

    $ids = [];
    foreach ($profiles as $profile) {
        $score = (float) ((is_array($profile['scores'] ?? null) ? $profile['scores'] : [])['24H']
            ?? (is_array($profile['scores'] ?? null) ? $profile['scores'] : [])['24h'] ?? 0);
        if ($score <= 0) {
            continue;
        }
        $ids[] = (string) $profile['id'];
        if (count($ids) >= $limit) {
            break;
        }
    }

    return $ids;
}

function p50_trigger_find_event_index(array $events, string $profileId): ?int {
    foreach ($events as $index => $event) {
        if (is_array($event) && (string) ($event['profileId'] ?? '') === $profileId) {
            return (int) $index;
        }
    }

    return null;
}

function p50_trigger_reason_for_rank(int $rank): string {
    if ($rank > 0 && $rank <= 10) {
        return 'Ce contenu récent explique la progression de cette fiche dans le Top 10.';
    }

    return 'Ce contenu récent explique la progression de cette fiche dans le Top 50.';
}

function p50_trigger_is_video_type(string $itemType, string $platform = ''): bool {
    if (preg_match('/video|reel|live|short/u', strtolower($itemType)) === 1) {
        return true;
    }

    return in_array($platform, ['YouTube', 'TikTok'], true);
}

function p50_trigger_query_news(PDO $pdo, string $profileId, int $hours, bool $videoOnly): ?array {
    $videoSql = $videoOnly
        ? " AND (LOWER(n.item_type) REGEXP 'video|reel|live|short' OR n.platform IN ('YouTube','TikTok'))"
        : '';
    $stmt = $pdo->prepare("SELECT n.platform,n.item_type,n.canonical_url,n.title,n.thumbnail_url,
        n.source_published_at,n.pass50_published_at,n.confidence
      FROM p50_news_items n
      WHERE BINARY n.profile_id=BINARY ?
        AND n.validation_status='published'
        AND n.is_official=1
        AND (n.expires_at IS NULL OR n.expires_at>UTC_TIMESTAMP())
        AND COALESCE(n.source_published_at,n.pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$hours." HOUR)
        AND n.canonical_url<>''".$videoSql."
      ORDER BY COALESCE(n.source_published_at,n.pass50_published_at) DESC,n.id DESC
      LIMIT 1");
    $stmt->execute([$profileId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function p50_trigger_query_metric_content(PDO $pdo, string $profileId, bool $videoOnly): ?array {
    if (!p50_metrics_table_exists($pdo, 'p50_metric_contents')) {
        return null;
    }
    $videoSql = $videoOnly
        ? " AND (LOWER(c.content_type) REGEXP 'video|reel|live|short' OR c.platform IN ('YouTube','TikTok'))"
        : '';
    $stmt = $pdo->prepare("SELECT c.platform,c.content_type AS item_type,c.canonical_url,c.title,
        c.published_at AS source_published_at,c.last_seen_at AS pass50_published_at,c.confidence,c.metadata_json,c.platform_content_id
      FROM p50_metric_contents c
      JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id
      WHERE BINARY c.profile_id=BINARY ?
        AND c.status='active'
        AND r.alive=1
        AND c.confidence>=70
        AND c.canonical_url<>''".$videoSql."
        AND COALESCE(c.published_at,c.last_seen_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 45 DAY)
      ORDER BY COALESCE(c.published_at,c.last_seen_at) DESC,c.id DESC
      LIMIT 1");
    $stmt->execute([$profileId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    if (trim((string) ($row['thumbnail_url'] ?? '')) === '' && function_exists('p50_ci_thumbnail')) {
        $metadata = function_exists('p50_ci_decode') ? p50_ci_decode((string) ($row['metadata_json'] ?? '')) : [];
        $thumb = p50_ci_thumbnail(
            (string) $row['platform'],
            (string) $row['canonical_url'],
            $row['platform_content_id'] !== null ? (string) $row['platform_content_id'] : null,
            $metadata
        );
        if ($thumb) {
            $row['thumbnail_url'] = $thumb;
        }
    }
    unset($row['metadata_json'], $row['platform_content_id']);

    return $row;
}

function p50_trigger_query_trend_content(PDO $pdo, string $profileId): ?array {
    if (!p50_metrics_table_exists($pdo, 'p50_content_trend_current')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT c.platform,c.content_type AS item_type,c.canonical_url,c.title,
        c.published_at AS source_published_at,c.last_seen_at AS pass50_published_at,c.confidence,c.metadata_json,c.platform_content_id,
        n.thumbnail_url
      FROM p50_content_trend_current t
      JOIN p50_metric_contents c ON c.id=t.content_id
      LEFT JOIN p50_news_items n ON n.content_id=c.id AND n.validation_status='published'
      WHERE BINARY c.profile_id=BINARY ?
        AND t.period_key='24h'
        AND c.status='active'
        AND c.canonical_url<>''
      ORDER BY t.rank_position ASC
      LIMIT 1");
    $stmt->execute([$profileId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    if (trim((string) ($row['thumbnail_url'] ?? '')) === '' && function_exists('p50_ci_thumbnail')) {
        $metadata = function_exists('p50_ci_decode') ? p50_ci_decode((string) ($row['metadata_json'] ?? '')) : [];
        $thumb = p50_ci_thumbnail(
            (string) $row['platform'],
            (string) $row['canonical_url'],
            $row['platform_content_id'] !== null ? (string) $row['platform_content_id'] : null,
            $metadata
        );
        if ($thumb) {
            $row['thumbnail_url'] = $thumb;
        }
    }
    unset($row['metadata_json'], $row['platform_content_id']);

    return $row;
}

function p50_trigger_latest_content(PDO $pdo, string $profileId): ?array {
    foreach ([
        [72, true],
        [72, false],
        [168, true],
        [168, false],
    ] as [$hours, $videoOnly]) {
        $row = p50_trigger_query_news($pdo, $profileId, $hours, $videoOnly);
        if ($row !== null) {
            return $row;
        }
    }

    $row = p50_trigger_query_trend_content($pdo, $profileId);
    if ($row !== null) {
        return $row;
    }

    $row = p50_trigger_query_metric_content($pdo, $profileId, true);
    if ($row !== null) {
        return $row;
    }

    return p50_trigger_query_metric_content($pdo, $profileId, false);
}

function p50_trigger_latest_official_news(PDO $pdo, string $profileId): ?array {
    return p50_trigger_latest_content($pdo, $profileId);
}

function p50_trigger_relative_label(?string $publishedAt): string {
    $ts = $publishedAt ? strtotime($publishedAt . ' UTC') : false;
    if ($ts === false) {
        return 'Récent';
    }
    $seconds = max(0, time() - $ts);
    if ($seconds < 3600) {
        return 'il y a ' . max(1, (int) round($seconds / 60)) . ' min';
    }
    if ($seconds < 86400) {
        return 'il y a ' . (int) round($seconds / 3600) . ' h';
    }
    if ($seconds < 7 * 86400) {
        return 'il y a ' . (int) round($seconds / 86400) . ' j';
    }

    return gmdate('d M Y', $ts);
}

function p50_trigger_build_event(string $profileId, array $newsRow, array $profile, int $rank = 50): array {
    $platform = (string) ($newsRow['platform'] ?? 'Web');
    $url = trim((string) ($newsRow['canonical_url'] ?? ''));
    $itemType = strtolower((string) ($newsRow['item_type'] ?? ''));
    $isVideo = p50_trigger_is_video_type($itemType, $platform);
    $publishedAt = (string) ($newsRow['source_published_at'] ?? $newsRow['pass50_published_at'] ?? gmdate('Y-m-d H:i:s'));
    $publishedIso = gmdate('c', strtotime($publishedAt . ' UTC') ?: time());
    $title = trim((string) ($newsRow['title'] ?? ''));
    if ($title === '') {
        $title = 'Contenu récent de ' . trim((string) ($profile['name'] ?? 'cette FI'));
    }
    $thumb = trim((string) ($newsRow['thumbnail_url'] ?? ''));

    return [
        'type' => $isVideo ? 'Vidéo' : 'Article',
        'title' => $title,
        'platforms' => [$platform],
        'metric' => 'Contenu officiel détecté',
        'publishedLabel' => p50_trigger_relative_label($publishedAt),
        'reason' => p50_trigger_reason_for_rank($rank),
        'url' => $url,
        'submittedUrl' => $url,
        'resolvedUrl' => $url,
        'icon' => $isVideo ? '▶' : '📰',
        'confidence' => ((int) ($newsRow['confidence'] ?? 0)) >= 80 ? 'élevée' : 'moyenne',
        'originalLinkValidated' => true,
        'originalLinkValidatedAt' => $publishedIso,
        'autoSynced' => true,
        'manualDataValidated' => false,
        'coverCandidateUrl' => $thumb,
        'coverUrl' => '',
        'coverStatus' => $thumb !== '' ? 'validated' : 'missing',
        'coverSource' => 'content_intelligence',
        'coverNote' => 'Synchronisé automatiquement depuis la collecte officielle PASS50.',
    ];
}

function p50_trigger_should_replace(array $existing, array $incoming): bool {
    if (p50_trigger_event_is_stale($existing)) {
        return true;
    }
    if (!empty($existing['manualDataValidated'])) {
        $existingTs = strtotime((string) ($existing['originalLinkValidatedAt'] ?? ''));
        $incomingTs = strtotime((string) ($incoming['originalLinkValidatedAt'] ?? ''));
        if ($existingTs && $incomingTs && $existingTs >= $incomingTs) {
            return false;
        }
        if ($existingTs && !$incomingTs) {
            return false;
        }
    }
    $existingUrl = trim((string) ($existing['url'] ?? ''));
    $incomingUrl = trim((string) ($incoming['url'] ?? ''));
    if ($existingUrl !== '' && $existingUrl === $incomingUrl) {
        return false;
    }

    return true;
}

function p50_trigger_sync_content_entry(array &$state, string $profileId, string $url, string $platform): void {
    if (!isset($state['content']) || !is_array($state['content'])) {
        $state['content'] = [];
    }
    $foundIndex = null;
    foreach ($state['content'] as $index => $content) {
        if (is_array($content) && (string) ($content['profileId'] ?? '') === $profileId) {
            $foundIndex = (int) $index;
            break;
        }
    }
    if ($foundIndex === null) {
        $state['content'][] = [
            'id' => 'trend_' . $profileId . '_' . time(),
            'profileId' => $profileId,
            'platform' => $platform,
            'badge' => 'HOT',
            'views' => 'Contenu validé',
            'comments' => '',
            'time' => 'Récent',
            'url' => $url,
        ];

        return;
    }
    $state['content'][$foundIndex]['url'] = $url;
    $state['content'][$foundIndex]['platform'] = $platform;
    $state['content'][$foundIndex]['time'] = 'Récent';
}

function p50_trigger_sync_top50(PDO $pdo): array {
    if (!p50_metrics_table_exists($pdo, 'p50_news_items') || !p50_metrics_table_exists($pdo, 'app_state')) {
        return ['ok' => false, 'version' => P50_TRIGGER_SYNC_VERSION, 'writes' => 0, 'reason' => 'tables_missing'];
    }

    $pdo->beginTransaction();
    try {
        $state = p50_trigger_load_public_state($pdo);
        if ($state === null) {
            $pdo->rollBack();

            return ['ok' => false, 'version' => P50_TRIGGER_SYNC_VERSION, 'writes' => 0, 'reason' => 'state_missing'];
        }

        $profilesById = [];
        foreach ((array) ($state['profiles'] ?? []) as $profile) {
            if (is_array($profile) && !empty($profile['id'])) {
                $profilesById[(string) $profile['id']] = $profile;
            }
        }

        $events = array_values(array_filter((array) ($state['events'] ?? []), 'is_array'));
        $profileIds = p50_trigger_ranked_profile_ids($state, 50);
        $updated = 0;
        $skipped = 0;
        $cleared = 0;

        foreach ($profileIds as $index => $profileId) {
            $rank = $index + 1;
            $newsRow = p50_trigger_latest_content($pdo, $profileId);
            $eventIndex = p50_trigger_find_event_index($events, $profileId);
            $existing = $eventIndex !== null ? $events[$eventIndex] : null;

            if ($newsRow === null || trim((string) ($newsRow['canonical_url'] ?? '')) === '') {
                if (is_array($existing) && !empty($existing['autoSynced']) && p50_trigger_event_is_stale($existing)) {
                    unset($events[$eventIndex]);
                    $events = array_values($events);
                    $cleared++;
                } else {
                    $skipped++;
                }
                continue;
            }

            $profile = $profilesById[$profileId] ?? ['id' => $profileId, 'name' => 'Influenceur'];
            $incoming = p50_trigger_build_event($profileId, $newsRow, $profile, $rank);
            if (is_array($existing) && !p50_trigger_should_replace($existing, $incoming)) {
                $skipped++;
                continue;
            }

            if ($eventIndex !== null) {
                $eventId = (string) ($existing['id'] ?? ('trigger_auto_' . $profileId));
                $events[$eventIndex] = array_merge($existing, $incoming, ['id' => $eventId, 'profileId' => $profileId]);
            } else {
                $events[] = array_merge($incoming, [
                    'id' => 'trigger_auto_' . $profileId . '_' . time(),
                    'profileId' => $profileId,
                ]);
            }

            p50_trigger_sync_content_entry(
                $state,
                $profileId,
                (string) $incoming['url'],
                (string) ($incoming['platforms'][0] ?? 'Réseau social')
            );
            $updated++;
        }

        $state['events'] = array_values($events);
        $pdo->prepare("UPDATE app_state SET data=?,updated_by=NULL,updated_at=NOW() WHERE id='public'")
            ->execute([json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $pdo->commit();

        return [
            'ok' => true,
            'version' => P50_TRIGGER_SYNC_VERSION,
            'writes' => $updated + $cleared,
            'updated' => $updated,
            'cleared' => $cleared,
            'skipped' => $skipped,
            'profileCount' => count($profileIds),
            'top50Profiles' => count($profileIds),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('PASS50 trigger sync: ' . $error->getMessage());

        return [
            'ok' => false,
            'version' => P50_TRIGGER_SYNC_VERSION,
            'writes' => 0,
            'error' => substr($error->getMessage(), 0, 220),
        ];
    }
}

/** @deprecated Use p50_trigger_sync_top50() — kept for backward compatibility. */
function p50_trigger_sync_profiles(PDO $pdo): array {
    return p50_trigger_sync_top50($pdo);
}

/** @deprecated Use p50_trigger_sync_top50() — kept for backward compatibility. */
function p50_trigger_sync_top10(PDO $pdo): array {
    return p50_trigger_sync_top50($pdo);
}
