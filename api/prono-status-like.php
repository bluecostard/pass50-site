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
$alreadyLiked = (bool)$likeCheck->fetch();

$pdo->beginTransaction();
try {
    $fresh = $pdo->prepare('SELECT like_count,like_points_awarded,user_id FROM p50_prono_statuses WHERE id=? FOR UPDATE');
    $fresh->execute([$statusId]);
    $row = $fresh->fetch();
    if (!$row) {
        throw new RuntimeException('Statut introuvable');
    }

    if ($alreadyLiked) {
        $pdo->prepare('DELETE FROM p50_prono_status_likes WHERE status_id=? AND user_id=?')->execute([$statusId, $userId]);
        $pdo->prepare('UPDATE p50_prono_statuses SET like_count=GREATEST(0,like_count-1) WHERE id=?')->execute([$statusId]);
        $likeCount = max(0, (int)$row['like_count'] - 1);
        $liked = false;
    } else {
        $pdo->prepare('INSERT INTO p50_prono_status_likes(status_id,user_id) VALUES(?,?)')->execute([$statusId, $userId]);
        $pdo->prepare('UPDATE p50_prono_statuses SET like_count=like_count+1 WHERE id=?')->execute([$statusId]);
        $likeCount = (int)$row['like_count'] + 1;
        $liked = true;
    }

    $awarded = (float)$row['like_points_awarded'];
    $target = min($likeCount, P50_PRONO_STATUS_LIKE_CAP) * P50_PRONO_POINTS_STATUS_LIKE;
    $delta = round($target - $awarded, 2);
    if (abs($delta) >= 0.0001) {
        p50_prono_credit($pdo, (string)$row['user_id'], $delta, $liked ? 'status_like' : 'status_unlike', $statusId);
        $pdo->prepare('UPDATE p50_prono_statuses SET like_points_awarded=? WHERE id=?')->execute([$target, $statusId]);
    }
    $likeMilestones = [1, 5, 10, 25, 50, 100, 200];
    if ($liked && in_array($likeCount, $likeMilestones, true)) {
        $pdo->prepare('INSERT INTO notifications(user_id,title,body) VALUES(?,?,?)')->execute([
            (string)$row['user_id'],
            $likeCount === 1 ? 'Ton statut a reçu son premier like 💚' : 'Ton statut atteint '.$likeCount.' likes 💚',
            'Ton statut prono compte maintenant '.$likeCount.' like'.($likeCount > 1 ? 's' : '').' et continue de te rapporter des points.'
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PASS50 prono-status-like: '.$e->getMessage());
    json_response(['error' => 'Like impossible.'], 500);
}

json_response([
    'ok' => true,
    'liked' => $liked,
    'likeCount' => $likeCount,
    'pointsAwardedToAuthor' => $delta ?? 0,
]);
