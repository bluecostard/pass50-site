<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/weekly-digest-core.php';

require_method('GET');
header('Cache-Control: public, max-age=120, stale-while-revalidate=300');

$week = trim((string)($_GET['week'] ?? ''));
$preview = isset($_GET['preview']) && in_array(strtolower((string)$_GET['preview']), ['1', 'true', 'yes'], true);

try {
    db()->exec("SET time_zone = '+00:00'");
} catch (Throwable) {
}

$stats = p50_weekly_digest_compute_stats(db());
if ($week !== '' && $week !== (string)$stats['weekKey']) {
  // Historique : tenter la dernière exécution enregistrée.
  p50_weekly_digest_ensure_schema(db());
  $stmt = db()->prepare('SELECT stats_json FROM p50_weekly_digest_runs WHERE week_key=? LIMIT 1');
  $stmt->execute([$week]);
  $raw = $stmt->fetchColumn();
  $decoded = is_string($raw) ? json_decode($raw, true) : null;
  if (is_array($decoded)) {
    $stats = $decoded;
  }
}

if ($preview) {
  $stats = [
    'weekKey' => p50_weekly_digest_week_key(),
    'window' => p50_weekly_digest_window(),
    'topLive' => ['profileId' => 'census-samuella-kouassi', 'name' => 'Samuella Kouassi', 'viewers' => 12840, 'platform' => 'TikTok'],
    'topRankOne' => ['profileId' => 'census-roseline-layo', 'name' => 'Roseline Layo', 'timesFirst' => 5, 'periodKey' => '24H'],
    'topProno' => ['profileId' => 'census-jordan-evraa', 'name' => 'Jordan Evraa', 'voteCount' => 312, 'uniqueVoters' => 186],
  ];
}

json_response([
  'ok' => true,
  'version' => P50_WEEKLY_DIGEST_VERSION,
  'stats' => $stats,
  'message' => p50_weekly_digest_build_message($stats),
  'publicUrl' => '/bilan-semaine.php' . ($stats['weekKey'] ?? '' ? '?week=' . rawurlencode((string)$stats['weekKey']) : ''),
]);
