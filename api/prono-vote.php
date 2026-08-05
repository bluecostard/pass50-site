<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('POST');
p50_prono_ensure_schema();
p50_prono_lock_closed(db());

$user = auth_user();
$input = json_input();
$questionId = trim((string)($input['questionId'] ?? ''));
$optionKey = trim((string)($input['optionKey'] ?? ''));

if ($questionId === '' || $optionKey === '') {
    json_response(['error' => 'questionId et optionKey requis.'], 400);
}

$pdo = db();
$now = p50_prono_now()->format('Y-m-d H:i:s');
$stmt = $pdo->prepare("SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1");
$stmt->execute([$questionId]);
$question = $stmt->fetch();
if (!$question) json_response(['error' => 'Prono introuvable.'], 404);
if ((string)$question['status'] !== 'open' || (string)$question['opens_at'] > $now || (string)$question['closes_at'] <= $now) {
    json_response(['error' => 'Ce prono n’est plus ouvert.'], 409);
}

$validKeys = array_column(p50_prono_options($question['options_json'] ?? []), 'key');
if (!in_array($optionKey, $validKeys, true)) {
    json_response(['error' => 'Option invalide.'], 400);
}

$userId = (string)$user['id'];
$existing = $pdo->prepare('SELECT id FROM p50_prono_votes WHERE question_id=? AND user_id=? LIMIT 1');
$existing->execute([$questionId, $userId]);
$voteRow = $existing->fetch();

$pdo->beginTransaction();
try {
    $streakInfo = p50_prono_touch_streak($pdo, $userId);
    if ($voteRow) {
        $voteId = (string)$voteRow['id'];
        $pdo->prepare('UPDATE p50_prono_votes SET option_key=?, updated_at=UTC_TIMESTAMP(6) WHERE id=?')
            ->execute([$optionKey, $voteId]);
        $created = false;
    } else {
        $voteId = p50_prono_uuid();
        $pdo->prepare('INSERT INTO p50_prono_votes(id,question_id,user_id,option_key) VALUES(?,?,?,?)')
            ->execute([$voteId, $questionId, $userId, $optionKey]);
        $created = true;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PASS50 prono-vote: '.$e->getMessage());
    json_response(['error' => 'Impossible d’enregistrer le prono.'], 500);
}

json_response([
    'ok' => true,
    'voteId' => $voteId,
    'created' => $created,
    'optionKey' => $optionKey,
    'optionLabel' => p50_prono_option_label($question, $optionKey),
    'balance' => p50_prono_balance($pdo, $userId),
    'streak' => $streakInfo,
    'message' => 'Prono enregistré — sans argent réel.',
    'canPublishStatus' => true,
    'statusDurations' => P50_PRONO_STATUS_DURATIONS,
]);
