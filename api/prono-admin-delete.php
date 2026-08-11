<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('POST');
p50_prono_ensure_schema();

$user = auth_user();
require_role($user, 'owner', 'admin');
$input = json_input();

$id = trim((string)($input['id'] ?? ''));
if ($id === '') {
    json_response(['error' => 'ID requis.'], 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id, status FROM p50_prono_questions WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['error' => 'Prono introuvable.'], 404);
}

$voteStmt = $pdo->prepare('SELECT COUNT(*) FROM p50_prono_votes WHERE question_id = ?');
$voteStmt->execute([$id]);
$voteCount = (int)$voteStmt->fetchColumn();
$status = (string)($row['status'] ?? '');

// Soft-archive if resolved or has votes; otherwise hard delete
if ($voteCount > 0 || $status === 'resolved') {
    $pdo->prepare("UPDATE p50_prono_questions SET status='archived' WHERE id=?")
        ->execute([$id]);
    json_response([
        'ok' => true,
        'action' => 'archived',
        'id' => $id,
        'message' => 'Question archivee (votes ou deja resolue).',
    ]);
}

$pdo->prepare('DELETE FROM p50_prono_questions WHERE id = ?')->execute([$id]);

json_response([
    'ok' => true,
    'action' => 'deleted',
    'id' => $id,
    'message' => 'Question supprimee definitivement.',
]);
