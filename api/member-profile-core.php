<?php
declare(strict_types=1);

/**
 * Profil membre PASS50 (photo + date de naissance).
 */

function p50_member_ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function p50_member_ensure_schema(?PDO $pdo = null): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo = $pdo ?? db();
    p50_member_ensure_column($pdo, 'users', 'avatar_url', 'VARCHAR(500) NULL AFTER display_name');
    p50_member_ensure_column($pdo, 'users', 'birth_date', 'DATE NULL AFTER avatar_url');
    $done = true;
}

function p50_member_normalize_birth(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        throw new InvalidArgumentException('Date de naissance invalide (AAAA-MM-JJ).');
    }
    $y = (int)$m[1];
    $mo = (int)$m[2];
    $d = (int)$m[3];
    if (!checkdate($mo, $d, $y)) {
        throw new InvalidArgumentException('Date de naissance invalide.');
    }
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $y, $mo, $d), new DateTimeZone('UTC'));
    if (!$birth) {
        throw new InvalidArgumentException('Date de naissance invalide.');
    }
    $now = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $age = (int)$birth->diff($now)->y;
    if ($age < 13) {
        throw new InvalidArgumentException('Tu dois avoir au moins 13 ans.');
    }
    if ($age > 120) {
        throw new InvalidArgumentException('Date de naissance irréaliste.');
    }
    return $birth->format('Y-m-d');
}

function p50_member_public_avatar(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/uploads/avatars/')) {
        return $url;
    }
    return '';
}
