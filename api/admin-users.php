<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

$user = auth_user();
require_role($user, 'owner', 'admin');

$p50AdminUserRoles = ['member', 'admin', 'editor', 'verifier'];

function p50_admin_user_public(array $row): array {
    return [
        'id' => (string)$row['id'],
        'email' => (string)$row['email'],
        'displayName' => (string)$row['display_name'],
        'role' => (string)$row['role'],
        'emailConfirmed' => $row['email_confirmed_at'] !== null,
        'createdAt' => !empty($row['created_at'])
            ? gmdate('c', strtotime((string)$row['created_at'].' UTC'))
            : null,
        'updatedAt' => !empty($row['updated_at'])
            ? gmdate('c', strtotime((string)$row['updated_at'].' UTC'))
            : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 80)));
    $pdo = db();
    try {
        $sql = "SELECT id,email,display_name,role,email_confirmed_at,created_at,updated_at
          FROM users WHERE deleted_at IS NULL";
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (email LIKE ? OR display_name LIKE ?)';
            $like = '%'.$q.'%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT '.$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map('p50_admin_user_public', $stmt->fetchAll() ?: []);
    } catch (Throwable $e) {
        error_log('admin-users list: '.$e->getMessage());
        json_response(['error' => 'Impossible de lire les comptes inscrits.'], 500);
    }

    $stats = ['total' => count($items), 'admins' => 0, 'owners' => 0, 'confirmed' => 0, 'last7d' => 0];
    try {
        $statsStmt = $pdo->query("SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN role='admin' THEN 1 ELSE 0 END) AS admins,
            SUM(CASE WHEN role='owner' THEN 1 ELSE 0 END) AS owners,
            SUM(CASE WHEN email_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS last7d
          FROM users WHERE deleted_at IS NULL");
        $stats = $statsStmt ? ($statsStmt->fetch() ?: $stats) : $stats;
    } catch (Throwable $e) {
        error_log('admin-users stats: '.$e->getMessage());
    }

    json_response([
        'ok' => true,
        'canAssignRoles' => ($user['role'] ?? '') === 'owner',
        'assignableRoles' => $p50AdminUserRoles,
        'stats' => [
            'total' => (int)($stats['total'] ?? count($items)),
            'admins' => (int)($stats['admins'] ?? 0),
            'owners' => (int)($stats['owners'] ?? 0),
            'confirmed' => (int)($stats['confirmed'] ?? 0),
            'last7d' => (int)($stats['last7d'] ?? 0),
        ],
        'items' => $items,
    ]);
}

require_method('POST');
require_role($user, 'owner');

$input = json_input();
$targetId = trim((string)($input['userId'] ?? $input['id'] ?? ''));
$role = trim((string)($input['role'] ?? ''));
if ($targetId === '' || !in_array($role, $p50AdminUserRoles, true)) {
    json_response(['error' => 'Utilisateur ou rôle invalide. Rôles : member, admin.'], 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id,email,display_name,role,email_confirmed_at,created_at,updated_at FROM users WHERE id=? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$targetId]);
$target = $stmt->fetch();
if (!$target) {
    json_response(['error' => 'Compte introuvable.'], 404);
}

if ((string)$target['role'] === 'owner') {
    json_response(['error' => 'Le rôle propriétaire ne peut pas être modifié ici.'], 409);
}
if ((string)$target['id'] === (string)$user['id']) {
    json_response(['error' => 'Tu ne peux pas modifier ton propre rôle.'], 409);
}

$pdo->prepare('UPDATE users SET role=? WHERE id=? AND deleted_at IS NULL AND role<>\'owner\'')->execute([$role, $targetId]);
$fresh = $pdo->prepare('SELECT id,email,display_name,role,email_confirmed_at,created_at,updated_at FROM users WHERE id=? LIMIT 1');
$fresh->execute([$targetId]);
$row = $fresh->fetch();

json_response([
    'ok' => true,
    'item' => p50_admin_user_public($row ?: $target),
    'message' => 'Rôle mis à jour.',
]);
