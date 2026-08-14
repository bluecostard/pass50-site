<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

$user = auth_user();
$pdo = db();
$userId = (string)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT id,title,body,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
    $stmt->execute([$userId]);
    $items = array_map(static fn(array $row): array => [
        'id' => (string)$row['id'],
        'userId' => $userId,
        'title' => (string)$row['title'],
        'body' => (string)$row['body'],
        'read' => (bool)$row['is_read'],
        'createdAt' => strtotime((string)$row['created_at'])*1000,
    ], $stmt->fetchAll() ?: []);
    json_response(['ok' => true, 'items' => $items]);
}

require_method('POST');
$input = json_input();
$action = trim((string)($input['action'] ?? ''));

if ($action === 'mark_all_read') {
    $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0')->execute([$userId]);
    json_response(['ok' => true]);
}

if ($action === 'mark_read') {
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '' || !ctype_digit($id)) {
        json_response(['error' => 'Notification invalide.'], 422);
    }
    $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id, $userId]);
    json_response(['ok' => true]);
}

$targetUserId = trim((string)($input['userId'] ?? ''));
$title = trim((string)($input['title'] ?? ''));
$body = trim((string)($input['body'] ?? ''));
if ($targetUserId === '' || $title === '' || $body === '') {
    json_response(['error' => 'Notification invalide.'], 422);
}

$isAdmin = in_array((string)($user['role'] ?? ''), ['owner', 'admin'], true);
if ($targetUserId !== $userId && !$isAdmin) {
    json_response(['error' => 'Droits insuffisants.'], 403);
}

$stmt = $pdo->prepare('INSERT INTO notifications(user_id,title,body) VALUES(?,?,?)');
$stmt->execute([$targetUserId, mb_substr($title, 0, 190), $body]);
$id = (string)$pdo->lastInsertId();

json_response([
    'ok' => true,
    'item' => [
        'id' => $id,
        'userId' => $targetUserId,
        'title' => mb_substr($title, 0, 190),
        'body' => $body,
        'read' => false,
        'createdAt' => round(microtime(true)*1000),
    ],
], 201);
