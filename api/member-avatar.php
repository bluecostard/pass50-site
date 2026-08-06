<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/member-profile-core.php';

require_method('POST');
p50_member_ensure_schema();
$u = auth_user(true);

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'Fichier manquant.'], 422);
}

$file = $_FILES['file'];
$max = (int)($config['upload']['max_bytes'] ?? (2 * 1024 * 1024));
if ($max > 2 * 1024 * 1024) {
    $max = 2 * 1024 * 1024;
}
if ($file['size'] > $max) {
    json_response(['error' => 'Image trop volumineuse (max 2 Mo).'], 413);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = $config['upload']['allowed_mime'] ?? ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    json_response(['error' => 'Format non autorisé (JPEG, PNG ou WebP).'], 422);
}

$ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? 'jpg';
$dir = dirname(__DIR__).'/uploads/avatars';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    json_response(['error' => 'Dossier avatar inaccessible.'], 500);
}

$safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$u['id']) ?: 'member';
$name = $safeId.'-'.time().'-'.bin2hex(random_bytes(3)).'.'.$ext;
$dest = $dir.'/'.$name;
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    json_response(['error' => 'Téléversement impossible.'], 500);
}

$url = rtrim((string)$config['app']['base_url'], '/').'/uploads/avatars/'.$name;
$stmt = db()->prepare('UPDATE users SET avatar_url=? WHERE id=? AND deleted_at IS NULL');
$stmt->execute([$url, $u['id']]);

$fresh = auth_user(true);
json_response(['ok' => true, 'url' => $url, 'user' => user_payload($fresh)], 201);
