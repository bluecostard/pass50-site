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
$title = trim((string)($input['title'] ?? ''));
$context = trim((string)($input['context'] ?? ''));
$profileId = trim((string)($input['profileId'] ?? ''));
$options = p50_prono_options($input['options'] ?? []);
$metricType = trim((string)($input['metricType'] ?? 'manual'));
$status = trim((string)($input['status'] ?? 'draft'));
$opensAt = trim((string)($input['opensAt'] ?? ''));
$closesAt = trim((string)($input['closesAt'] ?? ''));
$measureAt = trim((string)($input['measureAt'] ?? ''));
$voteHours = (int)($input['voteDurationHours'] ?? 0);
// Compat ancienne API jours → heures (2j→48h max hors grille ; on force la grille courte)
if ($voteHours <= 0 && isset($input['voteDurationDays'])) {
    $legacyDays = (int)$input['voteDurationDays'];
    $voteHours = $legacyDays === 2 ? 12 : ($legacyDays === 3 ? 24 : ($legacyDays === 7 ? 24 : 0));
}
$pointsCorrect = max(1, (int)($input['pointsCorrect'] ?? P50_PRONO_POINTS_CORRECT));

if ($title === '' || count($options) < 2) {
    json_response(['error' => 'Titre et au moins 2 options requis.'], 400);
}
if (!in_array($status, ['draft', 'open', 'locked', 'archived'], true)) {
    json_response(['error' => 'Statut invalide.'], 400);
}
if (!in_array($metricType, ['manual', 'followers_delta', 'rank_position', 'rank_delta', 'live_appeared', 'score_threshold'], true)) {
    json_response(['error' => 'Métrique invalide.'], 400);
}

$now = p50_prono_now();
$opens = $opensAt !== '' ? new DateTimeImmutable($opensAt, new DateTimeZone('UTC')) : $now;

if (in_array($voteHours, P50_PRONO_VOTE_HOURS, true)) {
    $closes = $opens->modify('+' . $voteHours . ' hours');
} elseif ($closesAt !== '') {
    $closes = new DateTimeImmutable($closesAt, new DateTimeZone('UTC'));
} else {
    json_response(['error' => 'Durée de vote requise : 6, 12 ou 24 heures.'], 400);
}

if ($closes <= $opens) {
    json_response(['error' => 'closesAt doit être après opensAt.'], 400);
}

if ($measureAt === '') {
    json_response(['error' => 'Date de mesure requise.'], 400);
}
$measure = new DateTimeImmutable($measureAt, new DateTimeZone('UTC'));
if ($measure < $closes) {
    json_response(['error' => 'La date de mesure doit être ≥ clôture des votes.'], 400);
}

$pdo = db();
$optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE);
$metricConfig = isset($input['metricConfig']) && is_array($input['metricConfig'])
    ? json_encode($input['metricConfig'], JSON_UNESCAPED_UNICODE)
    : null;

if ($id === '') {
    $id = p50_prono_uuid();
    $pdo->prepare('INSERT INTO p50_prono_questions
      (id,title,context_text,profile_id,options_json,metric_type,metric_config_json,opens_at,closes_at,measure_at,points_correct,status,created_by)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
            $id, mb_substr($title, 0, 220), mb_substr($context, 0, 500), mb_substr($profileId, 0, 100),
            $optionsJson, $metricType, $metricConfig,
            $opens->format('Y-m-d H:i:s'), $closes->format('Y-m-d H:i:s'), $measure->format('Y-m-d H:i:s'),
            $pointsCorrect, $status, (string)$user['id'],
        ]);
} else {
    $exists = $pdo->prepare('SELECT id FROM p50_prono_questions WHERE id=? LIMIT 1');
    $exists->execute([$id]);
    if (!$exists->fetch()) json_response(['error' => 'Prono introuvable.'], 404);
    $pdo->prepare('UPDATE p50_prono_questions SET title=?,context_text=?,profile_id=?,options_json=?,metric_type=?,metric_config_json=?,opens_at=?,closes_at=?,measure_at=?,points_correct=?,status=? WHERE id=?')
        ->execute([
            mb_substr($title, 0, 220), mb_substr($context, 0, 500), mb_substr($profileId, 0, 100),
            $optionsJson, $metricType, $metricConfig,
            $opens->format('Y-m-d H:i:s'), $closes->format('Y-m-d H:i:s'), $measure->format('Y-m-d H:i:s'),
            $pointsCorrect, $status, $id,
        ]);
}

$fresh = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
$fresh->execute([$id]);
json_response(['ok' => true, 'item' => p50_prono_question_public($fresh->fetch())]);
