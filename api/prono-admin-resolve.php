<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('POST');
p50_prono_ensure_schema();
p50_prono_lock_closed(db());

$user = auth_user();
require_role($user, 'owner', 'admin');
$input = json_input();
$questionId = trim((string)($input['questionId'] ?? ''));
$winningKey = trim((string)($input['winningOptionKey'] ?? ''));
$evidence = is_array($input['evidence'] ?? null) ? $input['evidence'] : ['note' => trim((string)($input['evidenceNote'] ?? ''))];

if ($questionId === '' || $winningKey === '') {
    json_response(['error' => 'questionId et winningOptionKey requis.'], 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
$stmt->execute([$questionId]);
$question = $stmt->fetch();
if (!$question) json_response(['error' => 'Prono introuvable.'], 404);
if ((string)$question['status'] === 'resolved') {
    json_response(['error' => 'Déjà résolu.'], 409);
}
if (!in_array((string)$question['status'], ['open', 'locked'], true)) {
    json_response(['error' => 'Ce prono ne peut pas être résolu (statut '.$question['status'].').'], 409);
}

$validKeys = array_column(p50_prono_options($question['options_json'] ?? []), 'key');
if (!in_array($winningKey, $validKeys, true)) {
    json_response(['error' => 'Option gagnante invalide.'], 400);
}

$points = (int)($question['points_correct'] ?? P50_PRONO_POINTS_CORRECT);
$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE p50_prono_questions SET status='resolved', winning_option_key=?, evidence_json=?, resolved_at=UTC_TIMESTAMP() WHERE id=?")
        ->execute([$winningKey, json_encode($evidence, JSON_UNESCAPED_UNICODE), $questionId]);

    $votes = $pdo->prepare('SELECT id,user_id,option_key FROM p50_prono_votes WHERE question_id=?');
    $votes->execute([$questionId]);
    $winners = 0;
    foreach ($votes->fetchAll() ?: [] as $vote) {
        if ((string)$vote['option_key'] !== $winningKey) continue;
        p50_prono_credit($pdo, (string)$vote['user_id'], $points, 'prono_correct', $questionId);
        $winners++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PASS50 prono-admin-resolve: '.$e->getMessage());
    json_response(['error' => 'Résolution impossible.'], 500);
}

json_response([
    'ok' => true,
    'questionId' => $questionId,
    'winningOptionKey' => $winningKey,
    'winnersPaid' => $winners,
    'pointsEach' => $points,
]);
