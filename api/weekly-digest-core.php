<?php
declare(strict_types=1);

/**
 * Bilan hebdomadaire PASS50 — notification obligatoire chaque vendredi 21h (Abidjan).
 * 1) Live avec le plus d’auditeurs
 * 2) Influenceur N°1 du classement le plus de fois
 * 3) Influenceur le plus pronostiqué
 */

const P50_WEEKLY_DIGEST_VERSION = 'WEEKLY-DIGEST-V1.0';
const P50_WEEKLY_DIGEST_KIND = 'weekly_digest';
const P50_WEEKLY_DIGEST_TZ = 'Africa/Abidjan';

function p50_weekly_digest_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function p50_weekly_digest_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_weekly_digest_runs (
        week_key CHAR(16) CHARACTER SET ascii PRIMARY KEY,
        dispatched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
        stats_json LONGTEXT NULL,
        dispatch_id VARCHAR(120) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_weekly_digest_week_key(?DateTimeImmutable $now = null): string {
    $tz = new DateTimeZone(P50_WEEKLY_DIGEST_TZ);
    $now = $now ?? new DateTimeImmutable('now', $tz);
    $now = $now->setTimezone($tz);
    // Semaine ISO centrée sur le vendredi de diffusion.
    return $now->format('o') . '-W' . $now->format('W');
}

function p50_weekly_digest_window(?DateTimeImmutable $now = null): array {
    $tz = new DateTimeZone(P50_WEEKLY_DIGEST_TZ);
    $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
    $end = $now;
    $start = $now->modify('-7 days');
    return [
        'start' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'end' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'label' => $start->format('d/m') . ' → ' . $end->format('d/m/Y'),
    ];
}

function p50_weekly_digest_profile_name(PDO $pdo, string $profileId): string {
    $profileId = trim($profileId);
    if ($profileId === '') return '';

    if (p50_weekly_digest_table_exists($pdo, 'p50_profile_registry')) {
        $stmt = $pdo->prepare('SELECT public_name FROM p50_profile_registry WHERE BINARY profile_id=BINARY ? LIMIT 1');
        $stmt->execute([$profileId]);
        $name = trim((string)$stmt->fetchColumn());
        if ($name !== '') return $name;
    }

    try {
        $raw = $pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1")->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : [];
        if (!is_array($decoded)) $decoded = [];
        $state = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        foreach ((array)($state['profiles'] ?? []) as $profile) {
            if (!is_array($profile)) continue;
            if (strcasecmp((string)($profile['id'] ?? ''), $profileId) === 0) {
                $name = trim((string)($profile['name'] ?? ''));
                if ($name !== '') return $name;
            }
        }
    } catch (Throwable $e) {
        error_log('PASS50 weekly digest profile name: ' . substr($e->getMessage(), 0, 200));
    }

    return $profileId;
}

/** @return array{profileId:string,name:string,viewers:int,platform:string,title:string,source:string}|null */
function p50_weekly_digest_top_live(PDO $pdo, string $startUtc, string $endUtc): ?array {
    $best = null;

    // Pic observé dans les captures métriques (meilleure approximation du pic d’audience).
    if (p50_weekly_digest_table_exists($pdo, 'p50_metric_captures')) {
        $stmt = $pdo->prepare("SELECT profile_id, live_viewers AS peak_viewers, platform
            FROM p50_metric_captures
            WHERE live_viewers IS NOT NULL AND live_viewers > 0
              AND quality_status = 'usable'
              AND profile_id IS NOT NULL AND profile_id <> ''
              AND observed_at >= ? AND observed_at <= ?
            ORDER BY live_viewers DESC, observed_at DESC
            LIMIT 1");
        $stmt->execute([$startUtc, $endUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['peak_viewers'] > 0) {
            $best = [
                'profileId' => (string)$row['profile_id'],
                'name' => '',
                'viewers' => (int)$row['peak_viewers'],
                'platform' => (string)($row['platform'] ?? ''),
                'title' => '',
                'source' => 'metric_captures',
            ];
        }
    }

    // Direct radar : dernière valeur viewers connue par stream (pas de colonne peak).
    if (p50_weekly_digest_table_exists($pdo, 'p50_live_streams')) {
        $stmt = $pdo->prepare("SELECT profile_id, viewers AS max_viewers, platform, title
            FROM p50_live_streams
            WHERE viewers IS NOT NULL AND viewers > 0
              AND profile_id IS NOT NULL AND profile_id <> ''
              AND COALESCE(ended_at, last_seen_at, started_at) >= ?
              AND COALESCE(ended_at, last_seen_at, started_at) <= ?
            ORDER BY viewers DESC, last_seen_at DESC
            LIMIT 1");
        $stmt->execute([$startUtc, $endUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['max_viewers'] > 0) {
            $candidate = [
                'profileId' => (string)$row['profile_id'],
                'name' => '',
                'viewers' => (int)$row['max_viewers'],
                'platform' => (string)($row['platform'] ?? ''),
                'title' => trim((string)($row['title'] ?? '')),
                'source' => 'live_streams',
            ];
            if ($best === null || $candidate['viewers'] > $best['viewers']) {
                $best = $candidate;
            }
        }
    }

    if ($best === null) return null;
    $best['name'] = p50_weekly_digest_profile_name($pdo, $best['profileId']);
    return $best;
}

/** @return array{profileId:string,name:string,timesFirst:int,periodKey:string}|null */
function p50_weekly_digest_top_rank_one(PDO $pdo, string $startUtc, string $endUtc): ?array {
    if (!p50_weekly_digest_table_exists($pdo, 'p50_ranking_snapshots')) {
        return null;
    }

    $periodKey = '24H';
    $stmt = $pdo->prepare("SELECT profile_id, COUNT(*) AS times_first
        FROM p50_ranking_snapshots
        WHERE rank_position = 1
          AND period_key = ?
          AND captured_at >= ? AND captured_at <= ?
        GROUP BY profile_id
        ORDER BY times_first DESC
        LIMIT 1");
    $stmt->execute([$periodKey, $startUtc, $endUtc]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['times_first'] <= 0) {
        // Fallback: any period if 24H has no captures.
        $stmt = $pdo->prepare("SELECT profile_id, COUNT(*) AS times_first, period_key
            FROM p50_ranking_snapshots
            WHERE rank_position = 1
              AND captured_at >= ? AND captured_at <= ?
            GROUP BY profile_id, period_key
            ORDER BY times_first DESC
            LIMIT 1");
        $stmt->execute([$startUtc, $endUtc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['times_first'] <= 0) return null;
        $periodKey = (string)$row['period_key'];
    }

    return [
        'profileId' => (string)$row['profile_id'],
        'name' => p50_weekly_digest_profile_name($pdo, (string)$row['profile_id']),
        'timesFirst' => (int)$row['times_first'],
        'periodKey' => $periodKey,
    ];
}

/** @return array{profileId:string,name:string,voteCount:int,uniqueVoters:int}|null */
function p50_weekly_digest_top_prono(PDO $pdo, string $startUtc, string $endUtc): ?array {
    if (!p50_weekly_digest_table_exists($pdo, 'p50_prono_votes')
        || !p50_weekly_digest_table_exists($pdo, 'p50_prono_questions')) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT q.profile_id, COUNT(v.id) AS vote_count, COUNT(DISTINCT v.user_id) AS unique_voters
        FROM p50_prono_votes v
        JOIN p50_prono_questions q ON q.id = v.question_id
        WHERE q.profile_id IS NOT NULL AND q.profile_id <> ''
          AND v.created_at >= ? AND v.created_at <= ?
          AND (q.source_type IS NULL OR q.source_type <> 'prono50_live')
        GROUP BY q.profile_id
        ORDER BY vote_count DESC, unique_voters DESC
        LIMIT 1");
    $stmt->execute([$startUtc, $endUtc]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['vote_count'] <= 0) return null;

    return [
        'profileId' => (string)$row['profile_id'],
        'name' => p50_weekly_digest_profile_name($pdo, (string)$row['profile_id']),
        'voteCount' => (int)$row['vote_count'],
        'uniqueVoters' => (int)$row['unique_voters'],
    ];
}

function p50_weekly_digest_compute_stats(PDO $pdo, ?DateTimeImmutable $now = null): array {
    $window = p50_weekly_digest_window($now);
    $topLive = p50_weekly_digest_top_live($pdo, $window['start'], $window['end']);
    $topRank = p50_weekly_digest_top_rank_one($pdo, $window['start'], $window['end']);
    $topProno = p50_weekly_digest_top_prono($pdo, $window['start'], $window['end']);

    return [
        'weekKey' => p50_weekly_digest_week_key($now),
        'window' => $window,
        'topLive' => $topLive,
        'topRankOne' => $topRank,
        'topProno' => $topProno,
    ];
}

function p50_weekly_digest_format_viewers(int $n): string {
    return number_format(max(0, $n), 0, ',', ' ');
}

function p50_weekly_digest_profile_photo_url(?PDO $pdo, string $profileId, int $size = 480): string {
    $profileId = trim($profileId);
    if ($profileId === '' || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $profileId)) {
        return '';
    }
    return '/api/weekly-digest-photo.php?id=' . rawurlencode($profileId) . '&size=' . max(32, min(512, $size));
}

function p50_weekly_digest_pdf_url(string $weekKey = ''): string {
    return '/api/weekly-digest-pdf.php' . ($weekKey !== '' ? '?week=' . rawurlencode($weekKey) : '');
}

function p50_weekly_digest_page_url(string $weekKey = ''): string {
    return '/bilan-semaine.php' . ($weekKey !== '' ? '?week=' . rawurlencode($weekKey) : '');
}

function p50_weekly_digest_build_message(array $stats): array {
    $label = (string)($stats['window']['label'] ?? '');
    $title = 'Bilan de la semaine PASS50';

    $liveLine = '1. Live le plus suivi : données insuffisantes cette semaine';
    if (!empty($stats['topLive']['name'])) {
        $platform = trim((string)($stats['topLive']['platform'] ?? ''));
        $suffix = $platform !== '' ? " sur {$platform}" : '';
        $liveLine = '1. Live le plus suivi : ' . $stats['topLive']['name'] . $suffix
            . ' (' . p50_weekly_digest_format_viewers((int)$stats['topLive']['viewers']) . ' auditeurs)';
    }

    $rankLine = '2. N°1 du classement le plus souvent : données insuffisantes cette semaine';
    if (!empty($stats['topRankOne']['name'])) {
        $times = (int)$stats['topRankOne']['timesFirst'];
        $rankLine = '2. N°1 du classement le plus souvent : ' . $stats['topRankOne']['name']
            . ' (' . $times . ' fois)';
    }

    $pronoLine = '3. Influenceur le plus pronostiqué : données insuffisantes cette semaine';
    if (!empty($stats['topProno']['name'])) {
        $votes = (int)$stats['topProno']['voteCount'];
        $pronoLine = '3. Influenceur le plus pronostiqué : ' . $stats['topProno']['name']
            . ' (' . $votes . ' pronostic' . ($votes > 1 ? 's' : '') . ')';
    }

    $body = "Semaine {$label}\n{$liveLine}\n{$rankLine}\n{$pronoLine}";

    return [
        'title' => $title,
        'body' => $body,
        'kind' => P50_WEEKLY_DIGEST_KIND,
        'actionUrl' => p50_weekly_digest_page_url((string)$stats['weekKey']),
        'pdfUrl' => p50_weekly_digest_pdf_url((string)$stats['weekKey']),
    ];
}

/** @return list<string> */
function p50_weekly_digest_subscriber_ids(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id FROM users WHERE deleted_at IS NULL ORDER BY created_at ASC");
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = trim((string)($row['id'] ?? ''));
        if ($id !== '') $ids[] = $id;
    }
    return $ids;
}

function p50_weekly_digest_already_sent(PDO $pdo, string $weekKey): bool {
    p50_weekly_digest_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM p50_weekly_digest_runs WHERE week_key=?');
    $stmt->execute([$weekKey]);
    return (int)$stmt->fetchColumn() > 0;
}

function p50_weekly_digest_mark_sent(PDO $pdo, string $weekKey, int $recipientCount, array $stats, string $dispatchId): void {
    p50_weekly_digest_ensure_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO p50_weekly_digest_runs(week_key,recipient_count,stats_json,dispatch_id) VALUES(?,?,?,?)
        ON DUPLICATE KEY UPDATE recipient_count=VALUES(recipient_count),stats_json=VALUES(stats_json),dispatch_id=VALUES(dispatch_id),dispatched_at=CURRENT_TIMESTAMP');
    $stmt->execute([
        $weekKey,
        $recipientCount,
        json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        mb_substr($dispatchId, 0, 120),
    ]);
}

/**
 * Diffuse le bilan à tous les abonnés (notification obligatoire).
 *
 * @return array{ok:bool,version:string,weekKey:string,sent:int,skipped:bool,message:array,stats:array,dispatchId:string}
 */
function p50_weekly_digest_run(PDO $pdo, string $dispatchId = '', bool $force = false, ?DateTimeImmutable $now = null): array {
    if (!function_exists('p50_notification_create')) {
        require_once __DIR__ . '/notification-core.php';
    }

    p50_weekly_digest_ensure_schema($pdo);
    $stats = p50_weekly_digest_compute_stats($pdo, $now);
    $weekKey = (string)$stats['weekKey'];
    $message = p50_weekly_digest_build_message($stats);

    if (!$force && p50_weekly_digest_already_sent($pdo, $weekKey)) {
        return [
            'ok' => true,
            'version' => P50_WEEKLY_DIGEST_VERSION,
            'weekKey' => $weekKey,
            'sent' => 0,
            'skipped' => true,
            'reason' => 'already_sent',
            'message' => $message,
            'stats' => $stats,
            'dispatchId' => $dispatchId,
        ];
    }

    $recipients = p50_weekly_digest_subscriber_ids($pdo);
    $sent = 0;
    foreach ($recipients as $userId) {
        p50_notification_create(
            $pdo,
            $userId,
            $message['title'],
            $message['body'],
            $message['kind'],
            $message['actionUrl']
        );
        $sent++;
    }

    p50_weekly_digest_mark_sent($pdo, $weekKey, $sent, $stats, $dispatchId);

    return [
        'ok' => true,
        'version' => P50_WEEKLY_DIGEST_VERSION,
        'weekKey' => $weekKey,
        'sent' => $sent,
        'skipped' => false,
        'message' => $message,
        'stats' => $stats,
        'dispatchId' => $dispatchId,
    ];
}
