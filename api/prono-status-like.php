<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-core.php';

require_method('POST');
p50_prono_ensure_schema();
p50_prono_expire_statuses(db());

$user = auth_user();
$input = json_input();
$statusId = trim((string)($input['statusId'] ?? ''));
if ($statusId === '') json_response(['error' => 'statusId requis.'], 400);

$pdo = db();
$userId = (string)$user['id'];

$stmt = $pdo->prepare("SELECT * FROM p50_prono_statuses WHERE id=? LIMIT 1");
$stmt->execute([$statusId]);
$status = $stmt->fetch();
if (!$status || (string)$status['status'] !== 'live') {
    json_response(['error' => 'Statut introuvable ou expiré.'], 404);
}
if ((string)$status['expires_at'] <= p50_prono_now()->format('Y-m-d H:i:s')) {
    $pdo->prepare("UPDATE p50_prono_statuses SET status='expired' WHERE id=?")->execute([$statusId]);
    json_response(['error' => 'Statut expiré.'], 409);
}
if ((string)$status['user_id'] === $userId) {
    json_response(['error' => 'Tu ne peux pas liker ton propre statut.'], 409);
}

$likeCheck = $pdo->prepare('SELECT 1 FROM p50_prono_status_likes WHERE status_id=? AND user_id=? LIMIT 1');
$likeCheck->execute([$statusId, $userId]);
if ($likeCheck->fetch()) {
    json_response(['ok' => true, 'alreadyLiked' => true, 'likeCount' => (int)$status['like_count']]);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO p50_prono_status_likes(status_id,user_id) VALUES(?,?)')->execute([$statusId, $userId]);
    $pdo->prepare('UPDATE p50_prono_statuses SET like_count=like_count+1 WHERE id=?')->execute([$statusId]);

    $fresh = $pdo->prepare('SELECT like_count,like_points_awarded,user_id FROM p50_prono_statuses WHERE id=? FOR UPDATE');
    $fresh->execute([$statusId]);
    $row = $fresh->fetch();
    $likeCount = (int)$row['like_count'];
    $awarded = (float)$row['like_points_awarded'];
    $target = min($likeCount, P50_PRONO_STATUS_LIKE_CAP) * P50_PRONO_POINTS_STATUS_LIKE;
    $delta = round($target - $awarded, 2);
    if ($delta > 0) {
        p50_prono_credit($pdo, (string)$row['user_id'], $delta, 'status_like', $statusId);
        $pdo->prepare('UPDATE p50_prono_statuses SET like_points_awarded=? WHERE id=?')->execute([$target, $statusId]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PASS50 prono-status-like: '.$e->getMessage());
    json_response(['error' => 'Like impossible.'], 500);
}

json_response([
    'ok' => true,
    'likeCount' => $likeCount,
    'pointsAwardedToAuthor' => $delta ?? 0,
]);
