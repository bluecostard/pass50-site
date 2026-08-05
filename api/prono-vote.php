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
$userId = (string)$user['id'];
p50_prono_ensure_balance($pdo, $userId);

$now = p50_prono_now()->format('Y-m-d H:i:s');
$stmt = $pdo->prepare("SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1");
$stmt->execute([$questionId]);
$question = $stmt->fetch();
if (!$question) json_response(['error' => 'Prono introuvable.'], 404);
if ((string)$question['status'] !== 'open' || (string)$question['opens_at'] > $now || (string)$question['closes_at'] <= $now) {
    json_response(['error' => 'Ce prono n’est plus ouvert.'], 409);
}

$options = p50_prono_options($question['options_json'] ?? []);
$validKeys = array_column($options, 'key');
if (!in_array($optionKey, $validKeys, true)) {
    json_response(['error' => 'Option invalide.'], 400);
}

$oddLocked = p50_prono_option_odd($options, $optionKey);
$stakeDesired = (int)($question['points_correct'] ?? P50_PRONO_POINTS_CORRECT);

$existing = $pdo->prepare('SELECT id,stake_locked FROM p50_prono_votes WHERE question_id=? AND user_id=? LIMIT 1');
$existing->execute([$questionId, $userId]);
$voteRow = $existing->fetch();

$pdo->beginTransaction();
try {
    $streakInfo = p50_prono_touch_streak($pdo, $userId);
    if ($voteRow) {
        $voteId = (string)$voteRow['id'];
        $stakeLocked = (int)($voteRow['stake_locked'] ?? 0);
        $pdo->prepare('UPDATE p50_prono_votes SET option_key=?, odd_locked=?, updated_at=UTC_TIMESTAMP(6) WHERE id=?')
            ->execute([$optionKey, $oddLocked, $voteId]);
        $created = false;
    } else {
        $voteId = p50_prono_uuid();
        $stakeLocked = p50_prono_debit_stake($pdo, $userId, $stakeDesired, $questionId);
        $pdo->prepare('INSERT INTO p50_prono_votes(id,question_id,user_id,option_key,odd_locked,stake_locked) VALUES(?,?,?,?,?,?)')
            ->execute([$voteId, $questionId, $userId, $optionKey, $oddLocked, $stakeLocked]);
        $created = true;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PASS50 prono-vote: '.$e->getMessage());
    json_response(['error' => 'Impossible d’enregistrer le prono.'], 500);
}

$effectiveStake = $stakeLocked > 0 ? $stakeLocked : $stakeDesired;
$potential = p50_prono_payout($effectiveStake, $oddLocked);
$tallies = p50_prono_vote_tallies($pdo, $questionId, $options);
$balance = p50_prono_balance($pdo, $userId);

$msg = $stakeLocked > 0
    ? 'Prono enregistré — mise '.$stakeLocked.' · cote '.$oddLocked.' · +'.$potential.' pts si correct (sinon tu perds la mise, plancher '.P50_PRONO_BALANCE_FLOOR.').'
    : 'Prono enregistré — au plancher ('.P50_PRONO_BALANCE_FLOOR.' pts) : tu joues sans risquer ta mise · cote '.$oddLocked.'.';

json_response([
    'ok' => true,
    'voteId' => $voteId,
    'created' => $created,
    'optionKey' => $optionKey,
    'optionLabel' => p50_prono_option_label($question, $optionKey),
    'oddLocked' => $oddLocked,
    'stake' => $stakeDesired,
    'stakeLocked' => $stakeLocked,
    'potentialPayout' => $potential,
    'floor' => P50_PRONO_BALANCE_FLOOR,
    'totalVotes' => $tallies['totalVotes'],
    'tallies' => $tallies['tallies'],
    'balance' => $balance,
    'streak' => $streakInfo,
    'message' => $msg.' Sans argent réel.',
    'canPublishStatus' => true,
    'statusDurations' => P50_PRONO_STATUS_DURATIONS,
]);
