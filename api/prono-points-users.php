<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

require_method('GET');
$user = auth_user();
$pdo = db();
$query = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($query) < 2) {
    json_response(['ok' => true, 'items' => []]);
}

$query = mb_substr($query, 0, 60);
$escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
$like = '%'.$escaped.'%';
$prefix = $escaped.'%';

$stmt = $pdo->prepare("SELECT id,display_name,avatar_url
  FROM users
  WHERE deleted_at IS NULL
    AND id<>?
    AND display_name IS NOT NULL
    AND display_name<>''
    AND display_name LIKE ? ESCAPE '\\\\'
  ORDER BY CASE WHEN display_name LIKE ? ESCAPE '\\\\' THEN 0 ELSE 1 END,
    display_name ASC
  LIMIT 8");
$stmt->execute([(string)$user['id'], $like, $prefix]);

$items = [];
foreach ($stmt->fetchAll() ?: [] as $row) {
    $items[] = [
        'id' => (string)$row['id'],
        'pseudo' => (string)$row['display_name'],
        'avatarUrl' => trim((string)($row['avatar_url'] ?? '')),
    ];
}

json_response(['ok' => true, 'items' => $items]);
