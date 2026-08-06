<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/member-profile-core.php';

p50_member_ensure_schema();
$u = auth_user(true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['ok' => true, 'user' => user_payload($u)]);
}

require_method('POST');
$in = json_input();

$displayName = array_key_exists('displayName', $in)
    ? trim((string)$in['displayName'])
    : (string)($u['display_name'] ?? '');
if ($displayName === '' || mb_strlen($displayName) > 40) {
    json_response(['error' => 'Nom d’affichage invalide (1–40 caractères).'], 422);
}

try {
    $birthDate = array_key_exists('birthDate', $in)
        ? p50_member_normalize_birth(isset($in['birthDate']) ? (string)$in['birthDate'] : null)
        : ($u['birth_date'] ?? null);
} catch (InvalidArgumentException $e) {
    json_response(['error' => $e->getMessage()], 422);
}

$avatarUrl = array_key_exists('avatarUrl', $in)
    ? p50_member_public_avatar((string)($in['avatarUrl'] ?? ''))
    : p50_member_public_avatar((string)($u['avatar_url'] ?? ''));

if (array_key_exists('clearAvatar', $in) && !empty($in['clearAvatar'])) {
    $avatarUrl = '';
}

$stmt = db()->prepare('UPDATE users SET display_name=?, avatar_url=?, birth_date=? WHERE id=? AND deleted_at IS NULL');
$stmt->execute([
    $displayName,
    $avatarUrl !== '' ? $avatarUrl : null,
    $birthDate,
    $u['id'],
]);

$fresh = auth_user(true);
json_response(['ok' => true, 'user' => user_payload($fresh), 'message' => 'Profil mis à jour.']);
