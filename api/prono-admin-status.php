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
$status = trim((string)($input['status'] ?? 'open'));

if ($id === '') {
    json_response(['error' => 'ID requis.'], 400);
}
if (!in_array($status, ['draft', 'open', 'locked', 'archived'], true)) {
    json_response(['error' => 'Statut invalide.'], 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    json_response(['error' => 'Prono introuvable.'], 404);
}

$current = (string)($row['status'] ?? '');
if ($current === 'resolved') {
    json_response(['error' => 'Impossible de changer le statut d une question deja resolue.'], 409);
}

if ($status === 'open') {
    if (p50_prono_question_cover($row) === '') {
        json_response(['error' => 'Image manquante — ajoute une image avant de publier.'], 400);
    }
    $subjectKey = (string)($row['subject_key'] ?? '');
    if ($subjectKey !== '') {
        $openCount = p50_prono_count_open_for_subject($pdo, $subjectKey, $id);
        if ($openCount >= P50_PRONO_MAX_OPEN_PER_SUBJECT) {
            json_response([
                'error' => 'Plafond sujet atteint (max '.P50_PRONO_MAX_OPEN_PER_SUBJECT.').',
                'subjectKey' => $subjectKey,
            ], 409);
        }
    }
}

$pdo->prepare('UPDATE p50_prono_questions SET status=? WHERE id=?')->execute([$status, $id]);
$fresh = $pdo->prepare('SELECT * FROM p50_prono_questions WHERE id=? LIMIT 1');
$fresh->execute([$id]);

json_response([
    'ok' => true,
    'item' => p50_prono_question_public($fresh->fetch()),
]);
