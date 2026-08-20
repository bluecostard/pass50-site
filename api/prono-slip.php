<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('POST');
p50_prono_ensure_schema();
p50_prono_lock_closed(db());

$user = auth_user();
$input = json_input();
$legsIn = is_array($input['legs'] ?? null) ? $input['legs'] : [];
$stakeDesired = max(1, (int)($input['stake'] ?? P50_PRONO_POINTS_CORRECT));

if (count($legsIn) < 1) {
    json_response(['error' => 'Au moins 1 selection requise.'], 400);
}
if (count($legsIn) > 8) {
    json_response(['error' => 'Maximum 8 pronos par grille.'], 400);
}

$pdo = db();
$userId = (string)$user['id'];
p50_prono_ensure_balance($pdo, $userId);
$now = p50_prono_now()->format('Y-m-d H:i:s');

$legs = [];
$seenQ = [];
$combined = 1.0;
$liveCount = 0;

foreach ($legsIn as $raw) {
    if (!is_array($raw)) continue;
    $questionId = trim((string)($raw['questionId'] ?? ''));
    $optionKey = trim((string)($raw['optionKey'] ?? ''));
    if ($questionId === '' || $optionKey === '') {
        json_response(['error' => 'Chaque selection doit avoir questionId et optionKey.'], 400);
    }
    if (isset($seenQ[$questionId])) {
        json_response(['error' => 'Une seule option par prono dans la grille.'], 400);
    }
    $seenQ[$questionId] = true;

    $stmt = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
    $stmt->execute([$questionId]);
    $question = $stmt->fetch();
    if (!$question) json_response(['error' => 'Prono introuvable: '.$questionId], 404);
    if ((string)$question['status'] !== 'open' || (string)$question['opens_at'] > $now || (string)$question['closes_at'] <= $now) {
        json_response(['error' => 'Prono plus ouvert: '.(string)$question['title']], 409);
    }

    $isLiveQuestion = p50_prono_is_live_question($question);
    if ($isLiveQuestion) {
        $liveCount++;
        $activeLive = p50_prono_live_active_session($pdo);
        $sessionId = trim((string)($question['live_session_id'] ?? ''));
        if (!$activeLive || $sessionId === '' || (string)$activeLive['id'] !== $sessionId) {
            json_response(['error' => 'Prono50 live n’est plus actif.'], 409);
        }
    } else {
        $dup = $pdo->prepare('SELECT id FROM p50_prono_votes WHERE question_id=? AND user_id=? LIMIT 1');
        $dup->execute([$questionId, $userId]);
        if ($dup->fetch()) {
            json_response(['error' => 'Deja valide sur: '.(string)$question['title']], 409);
        }
    }

    $options = p50_prono_options($question['options_json'] ?? []);
    $validKeys = array_column($options, 'key');
    if (!in_array($optionKey, $validKeys, true)) {
        json_response(['error' => 'Option invalide.'], 400);
    }
    $odd = p50_prono_option_odd($options, $optionKey);
    $combined *= $odd;
    $legs[] = [
        'questionId' => $questionId,
        'title' => (string)$question['title'],
        'context' => trim((string)($question['context_text'] ?? '')),
        'optionKey' => $optionKey,
        'optionLabel' => p50_prono_option_label($question, $optionKey),
        'odd' => $odd,
        'coverPhoto' => p50_prono_question_cover($question),
        'live' => $isLiveQuestion,
    ];
}

if ($legs === []) {
    json_response(['error' => 'Aucune selection valide.'], 400);
}
if ($liveCount > 0 && $liveCount !== count($legs)) {
    json_response(['error' => 'Les pronos live ne se mélangent pas à la grille du jour.'], 400);
}

$combined = round($combined, 4);
if ($combined < 1.01) $combined = 1.01;
$isLive = $liveCount > 0;
$isCombo = count($legs) >= 2;
$settledOdd = $isLive ? round($combined * P50_PRONO_LIVE_PAYOUT_MULTIPLIER, 4) : $combined;
$potential = p50_prono_payout($stakeDesired, $settledOdd);

$pdo->beginTransaction();
try {
    $streakInfo = p50_prono_touch_streak($pdo, $userId);
    $slipId = '';
    $stakeLocked = 0;
    $voteIds = [];

    if ($isCombo || $isLive) {
        $slipId = p50_prono_uuid();
        $stakeLocked = p50_prono_debit_stake($pdo, $userId, $stakeDesired, $slipId);
        $potential = p50_prono_payout($stakeLocked > 0 ? $stakeLocked : $stakeDesired, $settledOdd);
        $pdo->prepare('INSERT INTO p50_prono_slips(id,user_id,stake,stake_locked,combined_odd,potential_payout,status,legs_json)
          VALUES(?,?,?,?,?,?,?,?)')
            ->execute([
                $slipId,
                $userId,
                $stakeDesired,
                $stakeLocked,
                $settledOdd,
                $potential,
                'open',
                json_encode($legs, JSON_UNESCAPED_UNICODE),
            ]);
        foreach ($legs as $leg) {
            $voteId = p50_prono_uuid();
            // stake_locked=0 sur les legs : la mise est sur le slip
            $pdo->prepare('INSERT INTO p50_prono_votes(id,question_id,user_id,option_key,odd_locked,stake_locked,slip_id)
              VALUES(?,?,?,?,?,?,?)')
                ->execute([$voteId, $leg['questionId'], $userId, $leg['optionKey'], $leg['odd'], 0, $slipId]);
            $voteIds[] = ['questionId' => $leg['questionId'], 'voteId' => $voteId];
        }
    } else {
        $leg = $legs[0];
        $voteId = p50_prono_uuid();
        $stakeLocked = p50_prono_debit_stake($pdo, $userId, $stakeDesired, $leg['questionId']);
        $potential = p50_prono_payout($stakeLocked > 0 ? $stakeLocked : $stakeDesired, (float)$leg['odd']);
        $pdo->prepare('INSERT INTO p50_prono_votes(id,question_id,user_id,option_key,odd_locked,stake_locked,slip_id)
          VALUES(?,?,?,?,?,?,?)')
            ->execute([$voteId, $leg['questionId'], $userId, $leg['optionKey'], $leg['odd'], $stakeLocked, '']);
        $voteIds[] = ['questionId' => $leg['questionId'], 'voteId' => $voteId];
        $combined = (float)$leg['odd'];
        $settledOdd = $combined;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PASS50 prono-slip: '.$e->getMessage());
    json_response(['error' => 'Impossible de valider la grille.'], 500);
}

$balance = p50_prono_balance($pdo, $userId);
$msg = $isLive
    ? 'Prono50 live · gains x'.P50_PRONO_LIVE_PAYOUT_MULTIPLIER.' · mise '.($stakeLocked ?: $stakeDesired).' · +'.$potential.' pts si correct.'
    : ($isCombo
        ? 'Grille validee · '.count($legs).' pronos · cote combinee '.$combined.' · mise '.($stakeLocked ?: $stakeDesired).' · +'.$potential.' pts si tout est correct.'
        : 'Prono enregistre · cote '.$combined.' · mise '.($stakeLocked ?: $stakeDesired).' · +'.$potential.' pts si correct.');

json_response([
    'ok' => true,
    'mode' => $isLive ? 'prono50_live' : ($isCombo ? 'grille' : 'single'),
    'live' => $isLive,
    'livePayoutMultiplier' => $isLive ? P50_PRONO_LIVE_PAYOUT_MULTIPLIER : 1,
    'slipId' => $slipId !== '' ? $slipId : null,
    'legs' => $legs,
    'voteIds' => $voteIds,
    'combinedOdd' => $settledOdd,
    'stake' => $stakeDesired,
    'stakeLocked' => $stakeLocked,
    'potentialPayout' => $potential,
    'balance' => $balance,
    'streak' => $streakInfo,
    'message' => $msg.' Sans argent reel.',
    'canPublishStatus' => true,
    'statusDurations' => P50_PRONO_STATUS_DURATIONS,
    'questionIds' => array_column($legs, 'questionId'),
]);
