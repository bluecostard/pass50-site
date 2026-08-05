<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('GET');
p50_prono_ensure_schema();
p50_prono_expire_statuses(db());

$user = auth_user(false);
$pdo = db();
$limit = max(1, min(40, (int)($_GET['limit'] ?? 20)));

$stmt = $pdo->query("SELECT s.*, q.title AS question_title, q.options_json, q.profile_id, q.points_correct,
    v.odd_locked, v.stake_locked, u.display_name AS author_display_name
  FROM p50_prono_statuses s
  JOIN p50_prono_questions q ON q.id=s.question_id
  JOIN p50_prono_votes v ON v.id=s.vote_id
  JOIN users u ON u.id=s.user_id AND u.deleted_at IS NULL
  WHERE s.status='live' AND s.expires_at>UTC_TIMESTAMP()
  ORDER BY s.created_at DESC
  LIMIT {$limit}");
$rows = $stmt ? ($stmt->fetchAll() ?: []) : [];

$liked = [];
if ($user && $rows) {
    $ids = array_map(static fn($r) => (string)$r['id'], $rows);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $likeStmt = $pdo->prepare("SELECT status_id FROM p50_prono_status_likes WHERE user_id=? AND status_id IN ($ph)");
    $likeStmt->execute(array_merge([(string)$user['id']], $ids));
    foreach ($likeStmt->fetchAll() ?: [] as $like) {
        $liked[(string)$like['status_id']] = true;
    }
}

$items = [];
foreach ($rows as $row) {
    $row['option_label'] = p50_prono_option_label($row, (string)$row['option_key']);
    $items[] = p50_prono_status_public($row, !empty($liked[(string)$row['id']]));
}

json_response([
    'ok' => true,
    'version' => P50_PRONO_VERSION,
    'items' => $items,
]);
