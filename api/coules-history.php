<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('GET');

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS coules_history (
  snapshot_date DATE NOT NULL,
  profile_id VARCHAR(80) NOT NULL,
  attention_score DECIMAL(6,2) NOT NULL,
  score_7d DECIMAL(6,2) NOT NULL DEFAULT 0,
  score_15d DECIMAL(6,2) NOT NULL DEFAULT 0,
  current_rank INT NOT NULL DEFAULT 0,
  badges_json TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (snapshot_date, profile_id),
  INDEX idx_coules_history_profile_date (profile_id, snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$stmt = $pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1");
$raw = $stmt->fetchColumn();
$state = $raw ? json_decode((string)$raw, true) : null;
$profiles = is_array($state['profiles'] ?? null) ? $state['profiles'] : [];

$eligible = [];
foreach ($profiles as $profile) {
    if (!is_array($profile) || empty($profile['id']) || empty($profile['alive']) || empty($profile['eligible'])) continue;
    $scores = is_array($profile['scores'] ?? null) ? $profile['scores'] : [];
    $score7 = (float)($scores['7D'] ?? 0);
    $score15 = (float)($scores['15D'] ?? $score7);
    $attention = round(($score15 * 0.65) + ($score7 * 0.35), 2);
    $eligible[] = [
        'id' => (string)$profile['id'],
        'score7' => $score7,
        'score15' => $score15,
        'attention' => $attention,
        'badges' => array_values(array_filter((array)($profile['badges'] ?? []), 'is_string')),
    ];
}

usort($eligible, static fn(array $a, array $b): int => $b['score15'] <=> $a['score15']);
$today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
$insert = $pdo->prepare("INSERT IGNORE INTO coules_history
  (snapshot_date, profile_id, attention_score, score_7d, score_15d, current_rank, badges_json)
  VALUES (?, ?, ?, ?, ?, ?, ?)");
foreach ($eligible as $index => $profile) {
    $insert->execute([
        $today,
        $profile['id'],
        $profile['attention'],
        $profile['score7'],
        $profile['score15'],
        $index + 1,
        json_encode($profile['badges'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

$now = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$currentStart = $now->modify('-59 days')->format('Y-m-d');
$previousStart = $now->modify('-119 days')->format('Y-m-d');
$previousEnd = $now->modify('-60 days')->format('Y-m-d');

$coverageStmt = $pdo->prepare("SELECT
  COUNT(DISTINCT CASE WHEN snapshot_date BETWEEN ? AND ? THEN snapshot_date END) AS current_days,
  COUNT(DISTINCT CASE WHEN snapshot_date BETWEEN ? AND ? THEN snapshot_date END) AS previous_days,
  COUNT(DISTINCT snapshot_date) AS total_days
  FROM coules_history WHERE snapshot_date BETWEEN ? AND ?");
$coverageStmt->execute([$currentStart, $today, $previousStart, $previousEnd, $previousStart, $today]);
$coverage = $coverageStmt->fetch() ?: ['current_days' => 0, 'previous_days' => 0, 'total_days' => 0];
$currentDays = (int)$coverage['current_days'];
$previousDays = (int)$coverage['previous_days'];
$totalDays = (int)$coverage['total_days'];

// 50 relevés minimum dans chaque fenêtre de 60 jours : pas de duel avant une base solide.
if ($currentDays < 50 || $previousDays < 50) {
    json_response([
        'ok' => true,
        'status' => 'insufficient_history',
        'currentWindowDays' => $currentDays,
        'previousWindowDays' => $previousDays,
        'daysCollected' => min(120, $totalDays),
        'requiredDays' => 120,
        'candidates' => [],
    ]);
}

$agg = $pdo->prepare("SELECT profile_id,
  AVG(CASE WHEN snapshot_date BETWEEN ? AND ? THEN attention_score END) AS current_avg,
  COUNT(CASE WHEN snapshot_date BETWEEN ? AND ? THEN 1 END) AS current_count,
  AVG(CASE WHEN snapshot_date BETWEEN ? AND ? THEN attention_score END) AS previous_avg,
  MAX(CASE WHEN snapshot_date BETWEEN ? AND ? THEN attention_score END) AS previous_peak,
  COUNT(CASE WHEN snapshot_date BETWEEN ? AND ? THEN 1 END) AS previous_count
  FROM coules_history
  WHERE snapshot_date BETWEEN ? AND ?
  GROUP BY profile_id");
$agg->execute([
    $currentStart, $today,
    $currentStart, $today,
    $previousStart, $previousEnd,
    $previousStart, $previousEnd,
    $previousStart, $previousEnd,
    $previousStart, $today,
]);
$aggregates = $agg->fetchAll();

$currentMap = [];
foreach ($eligible as $index => $profile) {
    $currentMap[$profile['id']] = [
        'rank' => $index + 1,
        'score15' => $profile['score15'],
        'badges' => $profile['badges'],
    ];
}

$blockedBadges = ['LIVE', 'VIRAL', 'HOT', 'UP', 'NEW', 'RECORD'];
$candidates = [];
foreach ($aggregates as $row) {
    $id = (string)$row['profile_id'];
    $current = $currentMap[$id] ?? null;
    if (!$current) continue;
    $currentAvg = round((float)$row['current_avg'], 2);
    $previousAvg = round((float)$row['previous_avg'], 2);
    $previousPeak = round((float)$row['previous_peak'], 2);
    if ($previousAvg <= 0) continue;
    $drop = round((1 - ($currentAvg / $previousAvg)) * 100, 1);
    $hasBlockedBadge = count(array_intersect($blockedBadges, $current['badges'])) > 0;

    // Un « coulé » doit avoir été réellement visible avant, être durablement en baisse,
    // et ne surtout pas être actuellement dans les profils les plus en vogue.
    if ((int)$row['current_count'] < 50 || (int)$row['previous_count'] < 50) continue;
    if ($previousAvg < 50 && $previousPeak < 70) continue;
    if ($currentAvg > 42 || $drop < 45) continue;
    if ($current['score15'] >= 50 || $current['rank'] <= 20 || $hasBlockedBadge) continue;

    $candidates[] = [
        'profileId' => $id,
        'decline' => $drop,
        'currentAverage' => $currentAvg,
        'previousAverage' => $previousAvg,
        'previousPeak' => $previousPeak,
        'currentRank' => $current['rank'],
    ];
}

usort($candidates, static fn(array $a, array $b): int => $b['decline'] <=> $a['decline']);
$candidates = array_slice($candidates, 0, 2);

json_response([
    'ok' => true,
    'status' => count($candidates) >= 2 ? 'ready' : 'no_confirmed_decline',
    'currentWindowDays' => $currentDays,
    'previousWindowDays' => $previousDays,
    'daysCollected' => min(120, $totalDays),
    'requiredDays' => 120,
    'candidates' => $candidates,
]);
