<?php
declare(strict_types=1);

require_once __DIR__ . '/youtube-oauth-core.php';

const P50YA_SCHEMA_VERSION = 1;
const P50YA_DEFAULT_DAYS = 28;
const P50YA_ALLOWED_DAYS = [7, 28, 90];
const P50YA_REFRESH_COOLDOWN_SECONDS = 300;
const P50YA_REPORT_METRICS = [
    'views',
    'estimatedMinutesWatched',
    'averageViewDuration',
    'averageViewPercentage',
    'likes',
    'comments',
    'shares',
    'subscribersGained',
    'subscribersLost',
];

function p50ya_days(int $days): int {
    return in_array($days, P50YA_ALLOWED_DAYS, true) ? $days : P50YA_DEFAULT_DAYS;
}

function p50ya_date_range(int $days, ?DateTimeImmutable $now = null): array {
    $days = p50ya_days($days);
    $utc = new DateTimeZone('UTC');
    $today = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc)->setTime(0, 0, 0);
    $end = $today->modify('-1 day');
    $start = $end->modify('-' . ($days - 1) . ' days');
    return [
        'days' => $days,
        'startDate' => $start->format('Y-m-d'),
        'endDate' => $end->format('Y-m-d'),
    ];
}

function p50ya_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $path = dirname(__DIR__) . '/migration-youtube-analytics-v1.sql';
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException('Migration YouTube Analytics introuvable.');
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement !== '') db()->exec($statement);
    }
}

function p50ya_metric_value(mixed $value, bool $integer): int|float|null {
    if ($value === null || $value === '') return null;
    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) return null;
    $number = (float)$value;
    if (!is_finite($number) || $number < 0) return null;
    return $integer ? (int)round($number) : $number;
}

function p50ya_parse_report(array $payload): array {
    $metrics = [
        'views' => null,
        'estimatedMinutesWatched' => null,
        'averageViewDuration' => null,
        'averageViewPercentage' => null,
        'likes' => null,
        'comments' => null,
        'shares' => null,
        'subscribersGained' => null,
        'subscribersLost' => null,
        'netSubscribers' => null,
    ];
    $headers = is_array($payload['columnHeaders'] ?? null) ? $payload['columnHeaders'] : [];
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $row = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if ($row === null) return ['hasData' => false, 'metrics' => $metrics];

    $integerMetrics = ['views', 'likes', 'comments', 'shares', 'subscribersGained', 'subscribersLost'];
    foreach ($headers as $index => $header) {
        if (!is_array($header)) continue;
        $name = (string)($header['name'] ?? '');
        if (!array_key_exists($name, $metrics)) continue;
        $metrics[$name] = p50ya_metric_value($row[$index] ?? null, in_array($name, $integerMetrics, true));
    }
    if ($metrics['subscribersGained'] !== null && $metrics['subscribersLost'] !== null) {
        $metrics['netSubscribers'] = $metrics['subscribersGained'] - $metrics['subscribersLost'];
    }
    $hasData = false;
    foreach ($metrics as $name => $value) {
        if ($name !== 'netSubscribers' && $value !== null) {
            $hasData = true;
            break;
        }
    }
    return ['hasData' => $hasData, 'metrics' => $metrics];
}

function p50ya_connection(string $userId): array {
    p50yo_ensure_schema();
    $connection = p50yo_connection_for_user($userId);
    if (!$connection) throw new RuntimeException('Aucune chaîne YouTube connectée.');
    if ((string)$connection['status'] === 'reauthorization_required') {
        throw new RuntimeException('La chaîne YouTube doit être reconnectée.');
    }
    return $connection;
}

function p50ya_summary_from_row(array $row): array {
    $nullableInt = static fn(string $key): ?int => $row[$key] === null ? null : (int)$row[$key];
    $nullableFloat = static fn(string $key): ?float => $row[$key] === null ? null : (float)$row[$key];
    $gained = $nullableInt('subscribers_gained');
    $lost = $nullableInt('subscribers_lost');
    return [
        'snapshotId' => (int)$row['id'],
        'channelId' => (string)$row['channel_id'],
        'period' => [
            'days' => (int)$row['period_days'],
            'startDate' => (string)$row['start_date'],
            'endDate' => (string)$row['end_date'],
        ],
        'hasData' => (bool)$row['has_data'],
        'metrics' => [
            'views' => $nullableInt('views'),
            'estimatedMinutesWatched' => $nullableFloat('estimated_minutes_watched'),
            'averageViewDuration' => $nullableFloat('average_view_duration'),
            'averageViewPercentage' => $nullableFloat('average_view_percentage'),
            'likes' => $nullableInt('likes'),
            'comments' => $nullableInt('comments'),
            'shares' => $nullableInt('shares'),
            'subscribersGained' => $gained,
            'subscribersLost' => $lost,
            'netSubscribers' => $gained !== null && $lost !== null ? $gained - $lost : null,
        ],
        'fetchedAt' => (string)$row['fetched_at'] . 'Z',
    ];
}

function p50ya_latest_summary(string $userId, int $days = P50YA_DEFAULT_DAYS, ?string $channelId = null): ?array {
    p50ya_ensure_schema();
    $days = p50ya_days($days);
    $sql = 'SELECT * FROM p50_youtube_analytics_snapshots WHERE user_id=? AND period_days=?';
    $params = [$userId, $days];
    if ($channelId !== null && trim($channelId) !== '') {
        $sql .= ' AND channel_id=?';
        $params[] = $channelId;
    }
    $sql .= ' ORDER BY fetched_at DESC,id DESC LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return is_array($row) ? p50ya_summary_from_row($row) : null;
}

function p50ya_store_summary(string $userId, array $connection, array $range, array $report, string $rawHash): array {
    p50ya_ensure_schema();
    $metrics = (array)$report['metrics'];
    $snapshotKey = hash('sha256', implode('|', [
        $userId,
        (string)$connection['channel_id'],
        (string)$range['days'],
        (string)$range['startDate'],
        (string)$range['endDate'],
        $rawHash,
    ]));
    $sql = 'INSERT IGNORE INTO p50_youtube_analytics_snapshots
        (snapshot_key,user_id,channel_id,period_days,start_date,end_date,has_data,views,estimated_minutes_watched,average_view_duration,average_view_percentage,likes,comments,shares,subscribers_gained,subscribers_lost,raw_payload_hash,fetched_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())';
    $stmt = db()->prepare($sql);
    $stmt->execute([
        $snapshotKey,
        $userId,
        (string)$connection['channel_id'],
        (int)$range['days'],
        (string)$range['startDate'],
        (string)$range['endDate'],
        (int)(bool)$report['hasData'],
        $metrics['views'],
        $metrics['estimatedMinutesWatched'],
        $metrics['averageViewDuration'],
        $metrics['averageViewPercentage'],
        $metrics['likes'],
        $metrics['comments'],
        $metrics['shares'],
        $metrics['subscribersGained'],
        $metrics['subscribersLost'],
        $rawHash,
    ]);
    $id = (int)db()->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($id === 0) {
        $lookup = db()->prepare('SELECT id FROM p50_youtube_analytics_snapshots WHERE snapshot_key=? LIMIT 1');
        $lookup->execute([$snapshotKey]);
        $id = (int)$lookup->fetchColumn();
    }
    $rowStmt = db()->prepare('SELECT * FROM p50_youtube_analytics_snapshots WHERE id=? LIMIT 1');
    $rowStmt->execute([$id]);
    $row = $rowStmt->fetch();
    if (!is_array($row)) throw new RuntimeException('Enregistrement YouTube Analytics introuvable.');
    return p50ya_summary_from_row($row) + ['storedNew' => $stmt->rowCount() === 1];
}

function p50ya_fetch_summary(string $userId, int $days = P50YA_DEFAULT_DAYS): array {
    $days = p50ya_days($days);
    $connection = p50ya_connection($userId);
    $latest = p50ya_latest_summary($userId, $days, (string)$connection['channel_id']);
    if ($latest !== null) {
        $latestTime = strtotime((string)$latest['fetchedAt']);
        if ($latestTime !== false && $latestTime > time() - P50YA_REFRESH_COOLDOWN_SECONDS) {
            return $latest + ['cached' => true, 'storedNew' => false];
        }
    }

    $accessToken = p50yo_refresh_access_token($userId);
    $range = p50ya_date_range($days);
    $query = [
        'ids' => 'channel==MINE',
        'startDate' => $range['startDate'],
        'endDate' => $range['endDate'],
        'metrics' => implode(',', P50YA_REPORT_METRICS),
        'includeHistoricalChannelData' => 'true',
    ];
    $response = p50yo_http(
        'https://youtubeanalytics.googleapis.com/v2/reports?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        'GET',
        ['Authorization: Bearer ' . $accessToken, 'Accept: application/json']
    );
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $errorPayload = strtolower(json_encode($response['json'] ?? [], JSON_UNESCAPED_SLASHES) ?: '');
        $requiresReauthorization = (int)$response['status'] === 401
            || ((int)$response['status'] === 403 && (
                str_contains($errorPayload, 'insufficient authentication scopes')
                || str_contains($errorPayload, 'insufficientpermissions')
            ));
        if ($requiresReauthorization) {
            db()->prepare("UPDATE p50_youtube_oauth_connections SET status='reauthorization_required',last_error='YouTube Analytics authorization rejected.' WHERE user_id=?")
                ->execute([$userId]);
        }
        throw p50yo_google_error($response, 'Lecture de YouTube Analytics impossible');
    }
    $report = p50ya_parse_report((array)$response['json']);
    $rawHash = hash('sha256', (string)$response['body']);
    return p50ya_store_summary($userId, $connection, $range, $report, $rawHash) + ['cached' => false];
}
