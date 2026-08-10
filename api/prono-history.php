<?php
declare(strict_types=1);

/**
 * Historique de jeu utilisateur : en cours + finis (gagné / perdu).
 * Filtre : all | pending | won | lost
 */

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('GET');
p50_prono_ensure_schema();
p50_prono_lock_closed(db());

$user = auth_user();
$pdo = db();
$userId = (string)$user['id'];
$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'pending', 'won', 'lost'], true)) {
    $filter = 'all';
}
$limit = max(1, min(100, (int)($_GET['limit'] ?? 60)));

$stmt = $pdo->prepare("SELECT q.*,
    v.option_key AS my_option_key,
    v.odd_locked AS my_odd_locked,
    v.stake_locked AS my_stake_locked,
    v.updated_at AS voted_at
  FROM p50_prono_votes v
  JOIN p50_prono_questions q ON q.id=v.question_id
  WHERE v.user_id=? AND q.status IN ('open','locked','resolved')
  ORDER BY COALESCE(q.resolved_at, v.updated_at) DESC
  LIMIT 200");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll() ?: [];

$counts = ['all' => 0, 'pending' => 0, 'won' => 0, 'lost' => 0];
$items = [];

foreach ($rows as $row) {
    $status = (string)($row['status'] ?? '');
    $options = p50_prono_options($row['options_json'] ?? []);
    $vote = [
        'option_key' => (string)$row['my_option_key'],
        'odd_locked' => $row['my_odd_locked'] ?? null,
        'stake_locked' => $row['my_stake_locked'] ?? 0,
        'updated_at' => (string)$row['voted_at'],
    ];
    $tallies = $status === 'resolved'
        ? p50_prono_vote_tallies($pdo, (string)$row['id'], $options)
        : null;
    $item = p50_prono_question_public($row, $vote, $tallies);

    $stake = (int)($row['points_correct'] ?? P50_PRONO_POINTS_CORRECT);
    $stakeLocked = (int)($row['my_stake_locked'] ?? 0);
    $effectiveStake = $stakeLocked > 0 ? $stakeLocked : $stake;
    $odd = p50_prono_normalize_odd($row['my_odd_locked'] ?? null, p50_prono_option_odd($options, (string)$row['my_option_key']));

    if ($status === 'resolved') {
        $won = (string)$row['my_option_key'] === (string)($row['winning_option_key'] ?? '');
        $playStatus = $won ? 'won' : 'lost';
        $item['won'] = $won;
        $item['pointsEarned'] = $won ? p50_prono_payout($effectiveStake, $odd) : 0;
        $item['stakeLost'] = !$won ? $effectiveStake : 0;
    } else {
        $playStatus = 'pending';
        $item['won'] = null;
        $item['pointsEarned'] = 0;
        $item['stakeLost'] = 0;
        $item['potentialPayout'] = p50_prono_payout($effectiveStake, $odd);
        // Affinage UX : votes encore ouverts vs clos en attente de mesure
        $item['pendingPhase'] = $status === 'open' ? 'voting' : 'awaiting_measure';
    }

    $item['playStatus'] = $playStatus;
    $item['votedAt'] = !empty($row['voted_at'])
        ? gmdate('c', strtotime((string)$row['voted_at'].' UTC'))
        : null;

    $counts['all']++;
    $counts[$playStatus]++;

    if ($filter !== 'all' && $playStatus !== $filter) {
        continue;
    }
    $items[] = $item;
    if (count($items) >= $limit) {
        break;
    }
}

json_response([
    'ok' => true,
    'version' => P50_PRONO_VERSION,
    'filter' => $filter,
    'counts' => $counts,
    'balance' => p50_prono_balance($pdo, $userId),
    'items' => $items,
]);
