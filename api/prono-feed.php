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
if ($user) {
    $ids = array_map(static fn($q) => (string)$q['id'], $questions);
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $voteStmt = $pdo->prepare("SELECT * FROM p50_prono_votes WHERE user_id=? AND question_id IN ($placeholders)");
        $voteStmt->execute(array_merge([(string)$user['id']], $ids));
        foreach ($voteStmt->fetchAll() ?: [] as $vote) {
            $votes[(string)$vote['question_id']] = $vote;
        }
    }
}

$items = [];
foreach ($questions as $row) {
    $qid = (string)$row['id'];
    $items[] = p50_prono_question_public($row, $votes[$qid] ?? null);
}

$balance = $user ? p50_prono_balance($pdo, (string)$user['id']) : ['balance' => 0, 'streak' => 0, 'lastPlayDate' => null];

json_response([
    'ok' => true,
    'version' => P50_PRONO_VERSION,
    'disclaimer' => 'Sans argent réel — points PASS50 uniquement.',
    'balance' => $balance,
    'statusDurations' => P50_PRONO_STATUS_DURATIONS,
    'items' => $items,
    'auth' => $user !== null,
]);
