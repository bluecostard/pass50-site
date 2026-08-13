<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('POST');
p50_prono_ensure_schema();
p50_prono_expire_statuses(db());

$user = auth_user();
$input = json_input();
$duration = (int)($input['durationHours'] ?? 0);
$questionId = trim((string)($input['questionId'] ?? ''));
$slipId = trim((string)($input['slipId'] ?? ''));
$questionIds = is_array($input['questionIds'] ?? null)
    ? array_values(array_filter(array_map(static fn($v) => trim((string)$v), $input['questionIds'])))
    : [];

if (!in_array($duration, P50_PRONO_STATUS_DURATIONS, true)) {
    json_response(['error' => 'Durée (12, 24 ou 48 h) requise.'], 400);
}

$pdo = db();
$userId = (string)$user['id'];
$expires = p50_prono_now()->modify('+'.$duration.' hours')->format('Y-m-d H:i:s');

/**
 * @param list<array<string,mixed>> $votes
 * @return list<array<string,mixed>>
 */
function p50_prono_status_build_legs(PDO $pdo, array $votes): array {
    $legs = [];
    foreach ($votes as $vote) {
        $qid = (string)$vote['question_id'];
        $qStmt = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
        $qStmt->execute([$qid]);
        $question = $qStmt->fetch();
        if (!$question) continue;
        $optionKey = (string)$vote['option_key'];
        $odd = p50_prono_normalize_odd($vote['odd_locked'] ?? null, p50_prono_option_odd(p50_prono_options($question['options_json'] ?? []), $optionKey));
        $legs[] = [
            'questionId' => $qid,
            'voteId' => (string)$vote['id'],
            'title' => (string)$question['title'],
            'questionTitle' => (string)$question['title'],
            'context' => trim((string)($question['context_text'] ?? '')),
            'optionKey' => $optionKey,
            'optionLabel' => p50_prono_option_label($question, $optionKey),
            'odd' => $odd,
            'coverPhoto' => p50_prono_question_cover($question),
            'profileId' => (string)($question['profile_id'] ?? ''),
        ];
    }
    return $legs;
}

function p50_prono_status_publish_grille(PDO $pdo, array $user, string $slipId, int $duration, string $expires): void {
    $userId = (string)$user['id'];
    $slipStmt = $pdo->prepare('SELECT * FROM p50_prono_slips WHERE id=? AND user_id=? LIMIT 1');
    $slipStmt->execute([$slipId, $userId]);
    $slip = $slipStmt->fetch();
    if (!$slip) json_response(['error' => 'Grille introuvable.'], 404);

    $dupSlip = $pdo->prepare("SELECT id FROM p50_prono_statuses WHERE slip_id=? AND user_id=? LIMIT 1");
    $dupSlip->execute([$slipId, $userId]);
    if ($dupSlip->fetch()) json_response(['error' => 'Statut déjà publié pour cette grille.'], 409);

    $voteStmt = $pdo->prepare('SELECT * FROM p50_prono_votes WHERE slip_id=? AND user_id=? ORDER BY created_at ASC, id ASC');
    $voteStmt->execute([$slipId, $userId]);
    $votes = $voteStmt->fetchAll() ?: [];
    if (count($votes) < 2) json_response(['error' => 'Cette grille n’a pas assez de sélections.'], 409);

    // Remplace d’éventuels statuts uniques legacy (1 chip par leg) par 1 statut grille.
    $legacyIds = [];
    foreach ($votes as $vote) {
        $dup = $pdo->prepare('SELECT id FROM p50_prono_statuses WHERE vote_id=? LIMIT 1');
        $dup->execute([(string)$vote['id']]);
        $existingId = trim((string)($dup->fetchColumn() ?: ''));
        if ($existingId !== '') $legacyIds[] = $existingId;
    }
    if ($legacyIds !== []) {
        $ph = implode(',', array_fill(0, count($legacyIds), '?'));
        $pdo->prepare("DELETE FROM p50_prono_status_likes WHERE status_id IN ($ph)")->execute($legacyIds);
        $pdo->prepare("DELETE FROM p50_prono_statuses WHERE id IN ($ph)")->execute($legacyIds);
    }

    $legs = p50_prono_status_build_legs($pdo, $votes);
    if (count($legs) < 2) json_response(['error' => 'Impossible de construire le statut grille.'], 500);

    $anchor = $votes[0];
    $statusId = p50_prono_uuid();
    $combined = (float)$slip['combined_odd'];
    $pdo->prepare('INSERT INTO p50_prono_statuses(id,user_id,question_id,vote_id,option_key,duration_hours,expires_at,slip_id,legs_json,combined_odd)
      VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $statusId,
            $userId,
            (string)$anchor['question_id'],
            (string)$anchor['id'],
            'grille',
            $duration,
            $expires,
            $slipId,
            json_encode($legs, JSON_UNESCAPED_UNICODE),
            $combined,
        ]);

    $publicRow = [
        'id' => $statusId,
        'user_id' => $userId,
        'question_id' => (string)$anchor['question_id'],
        'option_key' => 'grille',
        'options_json' => '[]',
        'profile_id' => (string)($legs[0]['profileId'] ?? ''),
        'question_title' => 'Grille · '.count($legs).' pronos',
        'odd_locked' => $combined,
        'stake_locked' => (int)$slip['stake_locked'],
        'points_correct' => (int)$slip['stake'],
        'slip_stake' => (int)$slip['stake'],
        'slip_stake_locked' => (int)$slip['stake_locked'],
        'slip_id' => $slipId,
        'legs_json' => json_encode($legs, JSON_UNESCAPED_UNICODE),
        'combined_odd' => $combined,
        'like_count' => 0,
        'duration_hours' => $duration,
        'created_at' => p50_prono_now()->format('Y-m-d H:i:s.u'),
        'expires_at' => $expires,
        'status' => 'live',
        'author_display_name' => (string)($user['display_name'] ?? 'Membre PASS50'),
        'author_avatar_url' => (string)($user['avatar_url'] ?? ''),
    ];

    json_response([
        'ok' => true,
        'mode' => 'grille',
        'status' => p50_prono_status_public($publicRow, false),
        'message' => 'Statut grille publié dans Mon fil ('.count($legs).' pronos).',
    ]);
}

// Resolve grille from slipId or multi questionIds sharing a slip
if ($slipId === '' && count($questionIds) >= 2) {
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $stmt = $pdo->prepare("SELECT slip_id FROM p50_prono_votes
      WHERE user_id=? AND question_id IN ($placeholders) AND slip_id<>'' LIMIT 1");
    $stmt->execute(array_merge([$userId], $questionIds));
    $slipId = trim((string)($stmt->fetchColumn() ?: ''));
}
if ($slipId === '' && $questionId !== '') {
    $one = $pdo->prepare('SELECT slip_id FROM p50_prono_votes WHERE question_id=? AND user_id=? LIMIT 1');
    $one->execute([$questionId, $userId]);
    $maybe = trim((string)($one->fetchColumn() ?: ''));
    // Only auto-upgrade to grille if caller asked with multiple ids or explicit slip
    if ($maybe !== '' && count($questionIds) >= 2) $slipId = $maybe;
}

if ($slipId !== '') {
    p50_prono_status_publish_grille($pdo, $user, $slipId, $duration, $expires);
}

if ($questionId === '' && count($questionIds) === 1) {
    $questionId = $questionIds[0];
}
if ($questionId === '') {
    json_response(['error' => 'questionId ou slipId requis.'], 400);
}

$voteStmt = $pdo->prepare('SELECT * FROM p50_prono_votes WHERE question_id=? AND user_id=? LIMIT 1');
$voteStmt->execute([$questionId, $userId]);
$vote = $voteStmt->fetch();
if (!$vote) json_response(['error' => 'Vote d’abord sur ce prono.'], 409);

$voteSlip = trim((string)($vote['slip_id'] ?? ''));
if ($voteSlip !== '') {
    // Un prono de grille seul → publier toute la grille
    p50_prono_status_publish_grille($pdo, $user, $voteSlip, $duration, $expires);
}

$qStmt = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
$qStmt->execute([$questionId]);
$question = $qStmt->fetch();
if (!$question) json_response(['error' => 'Prono introuvable.'], 404);

$dup = $pdo->prepare('SELECT id FROM p50_prono_statuses WHERE vote_id=? LIMIT 1');
$dup->execute([(string)$vote['id']]);
if ($dup->fetch()) json_response(['error' => 'Statut déjà publié pour ce prono.'], 409);

$statusId = p50_prono_uuid();
$optionKey = (string)$vote['option_key'];
$pdo->prepare('INSERT INTO p50_prono_statuses(id,user_id,question_id,vote_id,option_key,duration_hours,expires_at,slip_id)
  VALUES(?,?,?,?,?,?,?,?)')
    ->execute([$statusId, $userId, $questionId, (string)$vote['id'], $optionKey, $duration, $expires, '']);

json_response([
    'ok' => true,
    'mode' => 'single',
    'status' => [
        'id' => $statusId,
        'mode' => 'single',
        'questionId' => $questionId,
        'questionTitle' => (string)$question['title'],
        'questionContext' => trim((string)($question['context_text'] ?? '')),
        'profileId' => (string)($question['profile_id'] ?? ''),
        'optionKey' => $optionKey,
        'optionLabel' => p50_prono_option_label($question, $optionKey),
        'odd' => p50_prono_normalize_odd($vote['odd_locked'] ?? null, p50_prono_option_odd(p50_prono_options($question['options_json'] ?? []), $optionKey)),
        'stake' => (int)($vote['stake_locked'] ?? 0) ?: (int)($question['points_correct'] ?? P50_PRONO_POINTS_CORRECT),
        'durationHours' => $duration,
        'expiresAt' => gmdate('c', strtotime($expires.' UTC')),
        'likeCount' => 0,
        'legs' => [],
        'legCount' => 1,
    ],
    'message' => 'Statut publié dans Mon fil.',
]);
