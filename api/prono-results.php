<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('GET');
p50_prono_ensure_schema();
p50_prono_lock_closed(db());

$user = auth_user();
$pdo = db();
$userId = (string)$user['id'];
$limit = max(1, min(40, (int)($_GET['limit'] ?? 20)));

$stmt = $pdo->prepare("SELECT q.*, v.option_key AS my_option_key, v.updated_at AS voted_at
  FROM p50_prono_votes v
  JOIN p50_prono_questions q ON q.id=v.question_id
  WHERE v.user_id=? AND q.status='resolved'
  ORDER BY q.resolved_at DESC
  LIMIT {$limit}");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll() ?: [];

$items = [];
foreach ($rows as $row) {
    $vote = [
        'option_key' => (string)$row['my_option_key'],
        'updated_at' => (string)$row['voted_at'],
    ];
    $item = p50_prono_question_public($row, $vote);
    $won = (string)$row['my_option_key'] === (string)($row['winning_option_key'] ?? '');
    $item['won'] = $won;
    $item['pointsEarned'] = $won ? (int)($row['points_correct'] ?? P50_PRONO_POINTS_CORRECT) : 0;
    $items[] = $item;
}

json_response([
    'ok' => true,
    'version' => P50_PRONO_VERSION,
    'balance' => p50_prono_balance($pdo, $userId),
    'items' => $items,
]);
