<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-grille-core.php';

require_method('GET');
p50_prono_grille_ensure_schema();

$pdo = db();
$row = p50_prono_grille_fetch_published_today($pdo);
$public = p50_prono_grille_public_row($row);

if (!$public) {
    json_response([
        'ok' => true,
        'ready' => false,
        'version' => P50_PRONO_GRILLE_VERSION,
        'message' => 'Aucune grille publiée pour aujourd’hui.',
        'themes' => p50_prono_grille_theme_defaults(),
    ]);
}

json_response([
    'ok' => true,
    'ready' => true,
    'version' => P50_PRONO_GRILLE_VERSION,
    'grille' => $public,
]);
