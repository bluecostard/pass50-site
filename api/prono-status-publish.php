<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('POST');
p50_prono_ensure_schema();
p50_prono_expire_statuses(db());

$user = auth_user();
$input = json_input();
$questionId = trim((string)($input['questionId'] ?? ''));
$duration = (int)($input['durationHours'] ?? 0);

if ($questionId === '' || !in_array($duration, P50_PRONO_STATUS_DURATIONS, true)) {
    json_response(['error' => 'questionId et durée (12, 24 ou 48 h) requis.'], 400);
}

$pdo = db();
$userId = (string)$user['id'];

$voteStmt = $pdo->prepare('SELECT * FROM p50_prono_votes WHERE question_id=? AND user_id=? LIMIT 1');
$voteStmt->execute([$questionId, $userId]);
$vote = $voteStmt->fetch();
if (!$vote) json_response(['error' => 'Vote d’abord sur ce prono.'], 409);

$qStmt = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
$qStmt->execute([$questionId]);
$question = $qStmt->fetch();
if (!$question) json_response(['error' => 'Prono introuvable.'], 404);

$dup = $pdo->prepare("SELECT id FROM p50_prono_statuses WHERE vote_id=? LIMIT 1");
$dup->execute([(string)$vote['id']]);
if ($dup->fetch()) json_response(['error' => 'Statut déjà publié pour ce prono.'], 409);

$statusId = p50_prono_uuid();
$expires = p50_prono_now()->modify('+'.$duration.' hours')->format('Y-m-d H:i:s');
$optionKey = (string)$vote['option_key'];

$pdo->prepare('INSERT INTO p50_prono_statuses(id,user_id,question_id,vote_id,option_key,duration_hours,expires_at)
  VALUES(?,?,?,?,?,?,?)')
    ->execute([$statusId, $userId, $questionId, (string)$vote['id'], $optionKey, $duration, $expires]);

json_response([
    'ok' => true,
    'status' => [
        'id' => $statusId,
        'questionId' => $questionId,
        'questionTitle' => (string)$question['title'],
        'optionKey' => $optionKey,
        'optionLabel' => p50_prono_option_label($question, $optionKey),
        'durationHours' => $duration,
        'expiresAt' => gmdate('c', strtotime($expires.' UTC')),
        'likeCount' => 0,
    ],
    'message' => 'Statut publié dans Mon fil.',
]);
