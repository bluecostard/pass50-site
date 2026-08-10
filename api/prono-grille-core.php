<?php
declare(strict_types=1);

const P50_PRONO_GRILLE_VERSION = 'PRONO-GRILLE-V1.0';

const P50_PRONO_GRILLE_THEMES = [
    'people_influenceurs' => [
        'key' => 'people_influenceurs',
        'label' => 'People influenceurs',
        'hint' => 'Choisis les influenceurs qui vont marquer la journée',
        'pickCount' => 3,
    ],
    'people_artiste_sportif' => [
        'key' => 'people_artiste_sportif',
        'label' => 'People Artiste/sportif',
        'hint' => 'Choisis l’artiste ou le sportif qui va se démarquer',
        'pickCount' => 1,
    ],
    'people_actualite' => [
        'key' => 'people_actualite',
        'label' => 'People Actualité',
        'hint' => 'Choisis la personnalité d’actualité du jour',
        'pickCount' => 1,
    ],
];

function p50_prono_grille_ensure_schema(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS p50_prono_grille (
        id VARCHAR(40) PRIMARY KEY,
        grille_date DATE NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'draft',
        closes_at DATETIME NOT NULL,
        themes_json LONGTEXT NOT NULL,
        updated_by VARCHAR(64) NULL,
        published_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_p50_prono_grille_date (grille_date),
        INDEX idx_p50_prono_grille_status (status, grille_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function p50_prono_grille_theme_defaults(): array {
    $out = [];
    foreach (P50_PRONO_GRILLE_THEMES as $theme) {
        $out[] = [
            'key' => $theme['key'],
            'label' => $theme['label'],
            'hint' => $theme['hint'],
            'pickCount' => (int)$theme['pickCount'],
            'people' => [],
        ];
    }
    return $out;
}

function p50_prono_grille_abs_photo(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    $url = ltrim(str_replace('\\', '/', $url), './');
    return 'https://pass50.store/' . $url;
}

function p50_prono_grille_normalize_people($people): array {
    if (!is_array($people)) return [];
    $out = [];
    $seen = [];
    foreach ($people as $person) {
        if (!is_array($person)) continue;
        $id = trim((string)($person['profileId'] ?? $person['id'] ?? ''));
        $name = trim((string)($person['name'] ?? ''));
        if ($id === '' || $name === '') continue;
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        $photo = p50_prono_grille_abs_photo((string)($person['photoUrl'] ?? $person['photo'] ?? ''));
        $out[] = [
            'profileId' => $id,
            'name' => $name,
            'photoUrl' => $photo,
            'initials' => trim((string)($person['initials'] ?? '')),
        ];
    }
    return $out;
}

function p50_prono_grille_normalize_themes($themes): array {
    $defaults = P50_PRONO_GRILLE_THEMES;
    $byKey = [];
    if (is_array($themes)) {
        foreach ($themes as $theme) {
            if (!is_array($theme)) continue;
            $key = trim((string)($theme['key'] ?? ''));
            if ($key === '' || !isset($defaults[$key])) continue;
            $byKey[$key] = $theme;
        }
    }
    $out = [];
    foreach ($defaults as $key => $def) {
        $raw = $byKey[$key] ?? [];
        $pick = (int)($raw['pickCount'] ?? $def['pickCount']);
        $pick = max(1, min(6, $pick));
        $out[] = [
            'key' => $key,
            'label' => (string)($raw['label'] ?? $def['label']),
            'hint' => (string)($raw['hint'] ?? $def['hint']),
            'pickCount' => $pick,
            'people' => p50_prono_grille_normalize_people($raw['people'] ?? []),
        ];
    }
    return $out;
}

function p50_prono_grille_public_row(?array $row): ?array {
    if (!$row) return null;
    $themes = json_decode((string)($row['themes_json'] ?? '[]'), true);
    return [
        'id' => (string)$row['id'],
        'date' => (string)$row['grille_date'],
        'status' => (string)$row['status'],
        'closesAt' => isset($row['closes_at']) ? gmdate('c', strtotime((string)$row['closes_at'].' UTC') ?: time()) : null,
        'publishedAt' => !empty($row['published_at']) ? gmdate('c', strtotime((string)$row['published_at'].' UTC') ?: time()) : null,
        'themes' => p50_prono_grille_normalize_themes($themes),
        'version' => P50_PRONO_GRILLE_VERSION,
    ];
}

function p50_prono_grille_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
}

function p50_prono_grille_fetch_by_date(PDO $pdo, string $date): ?array {
    $stmt = $pdo->prepare('SELECT * FROM p50_prono_grille WHERE grille_date=? LIMIT 1');
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function p50_prono_grille_fetch_published_today(PDO $pdo): ?array {
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM p50_prono_grille WHERE grille_date=? AND status='published' LIMIT 1");
    $stmt->execute([$today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    // fallback: dernière grille publiée encore ouverte
    $stmt = $pdo->query("SELECT * FROM p50_prono_grille WHERE status='published' AND closes_at>UTC_TIMESTAMP() ORDER BY grille_date DESC LIMIT 1");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return $row ?: null;
}
