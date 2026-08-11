<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('GET');
p50_prono_ensure_schema();
$pdo = db();
p50_prono_expire_statuses($pdo);
p50_prono_lock_closed($pdo);

$user = auth_user(false);
$now = p50_prono_now()->format('Y-m-d H:i:s');

$stmt = $pdo->prepare("SELECT * FROM p50_prono_questions
  WHERE status='open' AND opens_at<=? AND closes_at>?
  ORDER BY closes_at ASC LIMIT 50");
$stmt->execute([$now, $now]);
$questions = $stmt->fetchAll() ?: [];

$votes = [];
$statusPublished = [];
if ($user) {
    $ids = array_map(static fn($q) => (string)$q['id'], $questions);
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $voteStmt = $pdo->prepare("SELECT * FROM p50_prono_votes WHERE user_id=? AND question_id IN ($placeholders)");
        $voteStmt->execute(array_merge([(string)$user['id']], $ids));
        foreach ($voteStmt->fetchAll() ?: [] as $vote) {
            $votes[(string)$vote['question_id']] = $vote;
        }
        $voteIds = array_values(array_map(static fn($v) => (string)$v['id'], $votes));
        if ($voteIds) {
            $statusPlaceholders = implode(',', array_fill(0, count($voteIds), '?'));
            $statusStmt = $pdo->prepare("SELECT vote_id FROM p50_prono_statuses WHERE vote_id IN ($statusPlaceholders)");
            $statusStmt->execute($voteIds);
            foreach ($statusStmt->fetchAll() ?: [] as $statusRow) {
                $statusPublished[(string)$statusRow['vote_id']] = true;
            }
        }
    }
}

$items = [];
foreach ($questions as $row) {
    $qid = (string)$row['id'];
    $options = p50_prono_options($row['options_json'] ?? []);
    $tallies = p50_prono_vote_tallies($pdo, $qid, $options);
    $item = p50_prono_question_public($row, $votes[$qid] ?? null, $tallies);
    $vote = $votes[$qid] ?? null;
    $item['statusPublished'] = $vote ? isset($statusPublished[(string)$vote['id']]) : false;
    $items[] = $item;
}

$balance = $user ? p50_prono_balance($pdo, (string)$user['id']) : ['balance' => 0, 'streak' => 0, 'lastPlayDate' => null, 'floor' => P50_PRONO_BALANCE_FLOOR];

json_response([
    'ok' => true,
    'version' => P50_PRONO_VERSION,
    'disclaimer' => 'Sans argent réel — cotes en points PASS50. Départ 1000 · plancher 100 · perte = mise.',
    'balance' => $balance,
    'statusDurations' => P50_PRONO_STATUS_DURATIONS,
    'stakeDefault' => P50_PRONO_POINTS_CORRECT,
    'startingBalance' => P50_PRONO_STARTING_BALANCE,
    'balanceFloor' => P50_PRONO_BALANCE_FLOOR,
    'themes' => p50_prono_theme_catalog(),
    'items' => $items,
    'auth' => $user !== null,
]);
