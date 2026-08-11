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

// Check exists
$stmt = $pdo->prepare('SELECT id, status, vote_count FROM p50_prono_questions WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['error' => 'Prono introuvable.'], 404);
}

// Safety: don't hard-delete resolved questions that have votes — archive instead
$hasVotes = (int)($row['vote_count'] ?? 0) > 0;
$isResolved = $row['status'] === 'resolved';

if ($hasVotes || $isResolved) {
    // Soft delete: archive
    $pdo->prepare('UPDATE p50_prono_questions SET status = ? WHERE id = ?')
        ->execute(['archived', $id]);
    json_response(['ok' => true, 'action' => 'archived', 'id' => $id,
        'message' => 'Question archivée (avait des votes ou était résolue).']);
}

// Hard delete: no votes, not resolved
$pdo->prepare('DELETE FROM p50_prono_questions WHERE id = ?')->execute([$id]);

json_response(['ok' => true, 'action' => 'deleted', 'id' => $id,
    'message' => 'Question supprimée définitivement.']);
