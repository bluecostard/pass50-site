<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('GET');
p50_prono_ensure_schema();

$user = auth_user();
$pdo = db();
$userId = (string)$user['id'];
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$minDate = $today->modify('-1 year');
$defaultFrom = $today->modify('-3 months');

$parseDate = static function (string $value): ?DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    return $date && $date->format('Y-m-d') === $value ? $date : null;
};

$fromRaw = trim((string)($_GET['from'] ?? ''));
$toRaw = trim((string)($_GET['to'] ?? ''));
$from = $fromRaw === '' ? $defaultFrom : $parseDate($fromRaw);
$to = $toRaw === '' ? $today : $parseDate($toRaw);

if (!$from || !$to || $from > $to) {
    json_response(['error' => 'La période sélectionnée est invalide.'], 422);
}
if ($from < $minDate || $to > $today) {
    json_response(['error' => 'La recherche est limitée aux 12 derniers mois.'], 422);
}
if ($from < $to->modify('-3 months')) {
    json_response(['error' => 'Sélectionne une période de 3 mois maximum.'], 422);
}

$labels = [
    'prono_stake' => 'Mise engagée',
    'prono_correct' => 'Gain sur un pronostic',
    'prono_grille_win' => 'Gain sur une grille',
    'daily_first' => 'Bonus du premier prono du jour',
    'streak_3' => 'Bonus streak 3 jours',
    'streak_7' => 'Bonus streak 7 jours',
    'status_like' => 'Point gagné grâce à un like',
    'status_unlike' => 'Retrait d’un like',
    'points_gift_sent' => 'Points offerts à un membre',
    'points_gift_received' => 'Points reçus d’un membre',
];

$stmt = $pdo->prepare("SELECT id,delta,reason,created_at
  FROM p50_prono_points_ledger
  WHERE user_id=? AND created_at>=? AND created_at<?
  ORDER BY created_at DESC,id DESC
  LIMIT 250");
$stmt->execute([
    $userId,
    $from->format('Y-m-d 00:00:00'),
    $to->modify('+1 day')->format('Y-m-d 00:00:00'),
]);

$items = [];
foreach ($stmt->fetchAll() ?: [] as $row) {
    $reason = (string)$row['reason'];
    $items[] = [
        'id' => (string)$row['id'],
        'delta' => round((float)$row['delta'], 2),
        'reason' => $reason,
        'label' => $labels[$reason] ?? 'Mouvement de points',
        'createdAt' => gmdate('c', strtotime((string)$row['created_at'].' UTC')),
    ];
}

json_response([
    'ok' => true,
    'period' => [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'min' => $minDate->format('Y-m-d'),
        'max' => $today->format('Y-m-d'),
        'maxMonths' => 3,
        'lookbackMonths' => 12,
    ],
    'items' => $items,
]);
