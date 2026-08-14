<?php
declare(strict_types=1);

function p50_notification_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'kind' => "VARCHAR(60) NOT NULL DEFAULT '' AFTER body",
        'action_url' => "VARCHAR(500) NOT NULL DEFAULT '' AFTER kind",
    ];
    foreach ($columns as $name => $definition) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute(['notifications', $name]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN {$name} {$definition}");
        }
    }
}

function p50_notification_action_url(string $url): string {
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) return '';
    return mb_substr($url, 0, 500);
}

function p50_notification_create(PDO $pdo, string $userId, string $title, string $body, string $kind = '', string $actionUrl = ''): string {
    p50_notification_ensure_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO notifications(user_id,title,body,kind,action_url) VALUES(?,?,?,?,?)');
    $stmt->execute([
        $userId,
        mb_substr(trim($title), 0, 190),
        trim($body),
        mb_substr(trim($kind), 0, 60),
        p50_notification_action_url($actionUrl),
    ]);
    return (string)$pdo->lastInsertId();
}
