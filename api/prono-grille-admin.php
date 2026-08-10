<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require_once __DIR__.'/prono-grille-core.php';

p50_prono_grille_ensure_schema();
$pdo = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $user = auth_user();
    require_role($user, 'owner', 'admin');
    $date = trim((string)($_GET['date'] ?? ''));
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }
    $row = p50_prono_grille_fetch_by_date($pdo, $date);
    json_response([
        'ok' => true,
        'version' => P50_PRONO_GRILLE_VERSION,
        'date' => $date,
        'themeCatalog' => array_values(P50_PRONO_GRILLE_THEMES),
        'grille' => p50_prono_grille_public_row($row) ?: [
            'id' => null,
            'date' => $date,
            'status' => 'draft',
            'closesAt' => null,
            'themes' => p50_prono_grille_theme_defaults(),
        ],
    ]);
}

if ($method !== 'POST') {
    json_response(['error' => 'Méthode non autorisée.'], 405);
}

$user = auth_user();
require_role($user, 'owner', 'admin');
$input = json_input();

$date = trim((string)($input['date'] ?? ''));
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
}
$status = trim((string)($input['status'] ?? 'draft'));
if (!in_array($status, ['draft', 'published'], true)) {
    json_response(['error' => 'Statut invalide (draft|published).'], 400);
}

$themes = p50_prono_grille_normalize_themes($input['themes'] ?? []);
if ($status === 'published') {
    foreach ($themes as $theme) {
        $need = (int)$theme['pickCount'];
        $have = count($theme['people']);
        if ($have < $need) {
            json_response([
                'error' => 'Thème « '.$theme['label'].' » : ajoute au moins '.$need.' personne(s) avant publication.',
                'theme' => $theme['key'],
            ], 400);
        }
    }
}

$closesAt = trim((string)($input['closesAt'] ?? ''));
if ($closesAt === '') {
    $closes = new DateTimeImmutable($date.' 23:59:59', new DateTimeZone('UTC'));
} else {
    $closes = new DateTimeImmutable($closesAt);
}
$closesSql = $closes->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$themesJson = json_encode($themes, JSON_UNESCAPED_UNICODE);
$updatedBy = (string)($user['id'] ?? '');

$existing = p50_prono_grille_fetch_by_date($pdo, $date);
$publishedAt = $status === 'published' ? gmdate('Y-m-d H:i:s') : null;

if ($existing) {
    $id = (string)$existing['id'];
    if ($status !== 'published') {
        $publishedAt = $existing['published_at'] ?? null;
    } elseif (!empty($existing['published_at']) && (string)$existing['status'] === 'published') {
        $publishedAt = (string)$existing['published_at'];
    }
    $pdo->prepare('UPDATE p50_prono_grille SET status=?, closes_at=?, themes_json=?, updated_by=?, published_at=?, updated_at=UTC_TIMESTAMP() WHERE id=?')
        ->execute([$status, $closesSql, $themesJson, $updatedBy, $publishedAt, $id]);
} else {
    $id = p50_prono_grille_uuid();
    $pdo->prepare('INSERT INTO p50_prono_grille (id,grille_date,status,closes_at,themes_json,updated_by,published_at)
        VALUES(?,?,?,?,?,?,?)')
        ->execute([$id, $date, $status, $closesSql, $themesJson, $updatedBy, $publishedAt]);
}

$row = p50_prono_grille_fetch_by_date($pdo, $date);
json_response([
    'ok' => true,
    'version' => P50_PRONO_GRILLE_VERSION,
    'saved' => true,
    'grille' => p50_prono_grille_public_row($row),
]);
