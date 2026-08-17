<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/profile-tombstone-core.php';

function tombstone_assert(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, $message.PHP_EOL);
        exit(1);
    }
}

$state = [
    'profiles' => [
        ['id' => 'census-sheisthecode', 'name' => 'Sheisthecode'],
        ['id' => 'census-reine-a', 'name' => 'Reine A.'],
        ['id' => 'keep-me', 'name' => 'Keep'],
        ['id' => 'custom-deleted', 'name' => 'Custom'],
        ['id' => 'census-henri-michel', 'name' => 'Henri Michel'],
    ],
    'deletedProfileIds' => ['custom-deleted'],
    'content' => [
        ['id' => 'c1', 'profileId' => 'census-sheisthecode'],
        ['id' => 'c2', 'profileId' => 'keep-me'],
    ],
];

$removed = p50_apply_profile_tombstones($state);
$ids = array_column($state['profiles'], 'id');
tombstone_assert(in_array('keep-me', $ids, true), 'Le profil conservé a disparu.');
tombstone_assert(in_array('census-henri-michel', $ids, true), 'Henri Michel a été retombstoné à tort.');
tombstone_assert(!in_array('census-sheisthecode', $ids, true), 'Sheisthecode est encore dans la base.');
tombstone_assert(!in_array('census-reine-a', $ids, true), 'Reine A. est encore dans la base.');
tombstone_assert(!in_array('custom-deleted', $ids, true), 'La suppression admin n’a pas été honorée.');
tombstone_assert(count($state['content']) === 1 && $state['content'][0]['id'] === 'c2', 'Les contenus liés n’ont pas été purgés.');
tombstone_assert(in_array('census-jai-horreur-des-fautes-lofficiel', $state['deletedProfileIds'], true), 'La liste tombstone seed est absente.');
tombstone_assert(in_array('census-reine-a', $state['deletedProfileIds'], true), 'Reine A. n’est pas tombstonée.');
tombstone_assert(count($removed) === 3, 'Le décompte des retraites est inexact.');

$again = p50_apply_profile_tombstones($state);
tombstone_assert($again === [], 'La passe idempotente a encore retiré des fiches.');
echo "ok\n";
