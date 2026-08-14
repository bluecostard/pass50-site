<?php
declare(strict_types=1);

/**
 * Tableau de bord personnel Pronostics.
 * Résume le solde réel, les gains, les mises et la valeur des likes reçus.
 */

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('GET');
p50_prono_ensure_schema();

$user = auth_user();
$pdo = db();
$userId = (string)$user['id'];

$ledger = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN reason IN ('prono_correct','prono_grille_win') AND delta>0 THEN delta ELSE 0 END),0) AS prediction_points,
    COALESCE(SUM(CASE WHEN reason='prono_stake' AND delta<0 THEN -delta ELSE 0 END),0) AS stake_points,
    COALESCE(SUM(CASE WHEN reason IN ('daily_first','streak_3','streak_7') AND delta>0 THEN delta ELSE 0 END),0) AS bonus_points
  FROM p50_prono_points_ledger
  WHERE user_id=?");
$ledger->execute([$userId]);
$ledgerRow = $ledger->fetch() ?: [];

$status = $pdo->prepare("SELECT
    COALESCE(SUM(like_count),0) AS likes_received,
    COALESCE(SUM(like_points_awarded),0) AS like_points,
    COUNT(*) AS statuses_published
  FROM p50_prono_statuses
  WHERE user_id=?");
$status->execute([$userId]);
$statusRow = $status->fetch() ?: [];

json_response([
    'ok' => true,
    'version' => P50_PRONO_VERSION,
    'balance' => p50_prono_balance($pdo, $userId),
    'predictionPoints' => round((float)($ledgerRow['prediction_points'] ?? 0), 2),
    'stakePoints' => round((float)($ledgerRow['stake_points'] ?? 0), 2),
    'bonusPoints' => round((float)($ledgerRow['bonus_points'] ?? 0), 2),
    'likesReceived' => (int)($statusRow['likes_received'] ?? 0),
    'likePoints' => round((float)($statusRow['like_points'] ?? 0), 2),
    'statusesPublished' => (int)($statusRow['statuses_published'] ?? 0),
    'likePointRate' => P50_PRONO_POINTS_STATUS_LIKE,
]);
