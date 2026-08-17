<?php
declare(strict_types=1);

/**
 * Suppressions admin durables. Le recensement et les overlays ne peuvent
 * pas réinjecter ces identifiants une fois qu’ils sont tombstonés.
 */
const P50_TOMBSTONE_PROFILE_IDS = [
    'census-jai-horreur-des-fautes-lofficiel',
    'census-simon-adingra',
    'census-sheisthecode',
    'census-les-adresses-de-chez-nous',
    'census-epouse-gnahore',
    'census-le-brouteur',
    'census-oustaz-diakite-yaya',
    'census-reine-a',
];

function p50_normalize_profile_id(mixed $id): string
{
    return strtolower(trim((string)$id));
}

function p50_tombstone_ids(array $state = []): array
{
    $ids = [];
    foreach (array_merge(P50_TOMBSTONE_PROFILE_IDS, (array)($state['deletedProfileIds'] ?? [])) as $id) {
        $normalized = p50_normalize_profile_id($id);
        if ($normalized !== '') {
            $ids[$normalized] = $normalized;
        }
    }
    ksort($ids, SORT_STRING);
    return array_values($ids);
}

function p50_is_tombstoned_profile_id(mixed $id, array $state = []): bool
{
    $normalized = p50_normalize_profile_id($id);
    return $normalized !== '' && in_array($normalized, p50_tombstone_ids($state), true);
}

function p50_apply_profile_tombstones(array &$state): array
{
    $ids = array_fill_keys(p50_tombstone_ids($state), true);
    $state['deletedProfileIds'] = array_keys($ids);
    $removed = [];
    $profiles = is_array($state['profiles'] ?? null) ? $state['profiles'] : [];
    $state['profiles'] = array_values(array_filter($profiles, static function ($profile) use ($ids, &$removed) {
        if (!is_array($profile) || empty($profile['id'])) {
            return true;
        }
        $id = p50_normalize_profile_id($profile['id']);
        if (!isset($ids[$id])) {
            return true;
        }
        $removed[] = ['id' => $id, 'name' => (string)($profile['name'] ?? $id)];
        return false;
    }));
    foreach (['content', 'events', 'signals', 'liveStreams'] as $key) {
        if (!is_array($state[$key] ?? null)) {
            continue;
        }
        $state[$key] = array_values(array_filter($state[$key], static function ($row) use ($ids) {
            if (!is_array($row)) {
                return true;
            }
            foreach (['profileId', 'profile_id', 'influencerId'] as $field) {
                $ref = p50_normalize_profile_id($row[$field] ?? '');
                if ($ref !== '' && isset($ids[$ref])) {
                    return false;
                }
            }
            return true;
        }));
    }
    return $removed;
}
