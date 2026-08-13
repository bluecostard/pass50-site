<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require_once __DIR__.'/prono-core.php';

require_method('GET');
p50_prono_ensure_schema();

$user = auth_user();
require_role($user, 'owner', 'admin');
p50_de_sync_registry_from_state();

$profiles = array_map(static fn(array $row): array => [
    'id' => (string)$row['profile_id'],
    'name' => (string)$row['public_name'],
    'handle' => (string)($row['handle'] ?? ''),
    'category' => (string)($row['category'] ?? ''),
], p50_de_registry_profiles(null, 2000, 0, false));

usort($profiles, static function (array $a, array $b): int {
    $cmp = strcasecmp($a['name'], $b['name']);
    return $cmp !== 0 ? $cmp : strcasecmp($a['id'], $b['id']);
});

json_response([
    'ok' => true,
    'count' => count($profiles),
    'profiles' => $profiles,
]);
