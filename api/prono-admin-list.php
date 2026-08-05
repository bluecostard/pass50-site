<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('GET');
p50_prono_ensure_schema();
p50_prono_lock_closed(db());

$user = auth_user();
require_role($user, 'owner', 'admin');

$pdo = db();
$status = trim((string)($_GET['status'] ?? ''));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));

$sql = 'SELECT * FROM p50_prono_questions';
$params = [];
if ($status !== '' && in_array($status, ['draft', 'open', 'locked', 'resolved', 'archived'], true)) {
    $sql .= ' WHERE status=?';
    $params[] = $status;
}
$sql .= ' ORDER BY created_at DESC LIMIT '.$limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

$items = [];
foreach ($rows as $row) {
    $qid = (string)$row['id'];
    $voteCount = $pdo->prepare('SELECT COUNT(*) FROM p50_prono_votes WHERE question_id=?');
    $voteCount->execute([$qid]);
    $public = p50_prono_question_public($row);
    $public['voteCount'] = (int)$voteCount->fetchColumn();
    $items[] = $public;
}

json_response([
    'ok' => true,
    'version' => P50_PRONO_VERSION,
    'items' => $items,
]);
