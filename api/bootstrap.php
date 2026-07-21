<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Configuration serveur manquante. Copiez config.example.php vers config.php.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require $configFile;

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(['error' => 'Corps JSON invalide.'], 400);
    return $data;
}

function require_method(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        json_response(['error' => 'Méthode non autorisée.'], 405);
    }
}

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'], $d['name'], $d['charset']);
    try {
        $pdo = new PDO($dsn, $d['user'], $d['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $e) {
        error_log('DB: ' . $e->getMessage());
        json_response(['error' => 'Connexion à la base impossible.'], 503);
    }
    return $pdo;
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return trim($m[1]);
    return null;
}

function auth_user(bool $required = true): ?array {
    $token = bearer_token();
    if (!$token) {
        if ($required) json_response(['error' => 'Connexion requise.'], 401);
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT u.id,u.email,u.display_name,u.role,u.email_confirmed_at,u.deleted_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>NOW() LIMIT 1');
    $stmt->execute([$hash]);
    $u = $stmt->fetch();
    if (!$u || $u['deleted_at'] !== null) {
        if ($required) json_response(['error' => 'Session expirée.'], 401);
        return null;
    }
    return $u;
}

function require_role(array $user, string ...$roles): void {
    if (!in_array($user['role'], $roles, true)) json_response(['error' => 'Droits insuffisants.'], 403);
}

function create_session(string $userId): string {
    global $config;
    $token = bin2hex(random_bytes(32));
    $days = max(1, (int)$config['app']['session_days']);
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');
    $stmt = db()->prepare('INSERT INTO sessions(token_hash,user_id,expires_at) VALUES(?,?,?)');
    $stmt->execute([hash('sha256', $token), $userId, $expires]);
    return $token;
}

function decode_json_column(?string $value, mixed $fallback): mixed {
    if ($value === null || $value === '') return $fallback;
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
}

function user_payload(array $u): array {
    $stmt = db()->prepare('SELECT favorites,following,follow_alerts,notification_mode FROM user_preferences WHERE user_id=?');
    $stmt->execute([$u['id']]);
    $p = $stmt->fetch() ?: [];
    return [
        'id' => $u['id'],
        'email' => $u['email'],
        'displayName' => $u['display_name'],
        'role' => $u['role'],
        'favorites' => decode_json_column($p['favorites'] ?? null, []),
        'following' => decode_json_column($p['following'] ?? null, []),
        'followAlerts' => decode_json_column($p['follow_alerts'] ?? null, new stdClass()),
        'notificationMode' => $p['notification_mode'] ?? 'daily',
        'emailConfirmed' => $u['email_confirmed_at'] !== null,
    ];
}

function send_brevo_email(string $email, string $displayName, string $subject, string $htmlContent): void {
    global $config;
    $b = $config['brevo'];
    if (empty($b['api_key'])) throw new RuntimeException('Clé Brevo manquante.');
    $payload = [
        'sender' => ['email' => $b['sender_email'], 'name' => $b['sender_name']],
        'to' => [['email' => $email, 'name' => $displayName]],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $b['api_key']],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) throw new RuntimeException('Brevo: ' . ($err ?: (string)$body));
}

function pass50_email_template(string $preheader, string $title, string $message, string $buttonLabel, string $actionUrl, string $smallNote): string {
    global $config;
    $base = rtrim($config['app']['base_url'], '/');
    $logo = $base . '/assets/pass50-logo-email.png';
    $safeUrl = htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8');
    $safeLogo = htmlspecialchars($logo, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;padding:0;background:#050705;font-family:Arial,Helvetica,sans-serif;color:#f6f8f4">'
      . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '</div>'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#050705"><tr><td align="center" style="padding:28px 12px">'
      . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#0d110d;border:1px solid #293129;border-radius:22px;overflow:hidden">'
      . '<tr><td style="height:7px;background:#b7ff00;font-size:0">&nbsp;</td></tr>'
      . '<tr><td align="center" style="padding:28px 28px 12px"><img src="' . $safeLogo . '" width="390" alt="PASS50 — Qui dit quoi, qui va où ?" style="display:block;width:100%;max-width:390px;height:auto;border:0"></td></tr>'
      . '<tr><td style="padding:12px 34px 8px;text-align:center"><h1 style="margin:0;color:#ffffff;font-size:29px;line-height:1.18">' . $title . '</h1></td></tr>'
      . '<tr><td style="padding:8px 34px 22px;text-align:center;color:#cbd3c8;font-size:16px;line-height:1.6">' . $message . '</td></tr>'
      . '<tr><td align="center" style="padding:0 34px 26px"><a href="' . $safeUrl . '" style="display:inline-block;background:#b7ff00;color:#050705;text-decoration:none;font-size:16px;font-weight:900;padding:15px 24px;border-radius:13px">' . $buttonLabel . '</a></td></tr>'
      . '<tr><td style="padding:0 34px 24px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
      . '<td align="center" style="width:33%;padding:13px 5px;color:#ffffff;font-size:12px;border-top:1px solid #293129">★ Favoris</td>'
      . '<td align="center" style="width:33%;padding:13px 5px;color:#ffffff;font-size:12px;border-top:1px solid #293129">🔔 Suivis</td>'
      . '<td align="center" style="width:33%;padding:13px 5px;color:#ffffff;font-size:12px;border-top:1px solid #293129">⚡ Alertes</td>'
      . '</tr></table></td></tr>'
      . '<tr><td style="padding:0 34px 30px;text-align:center;color:#8f998d;font-size:12px;line-height:1.55">' . $smallNote . '<br><br>Si le bouton ne fonctionne pas, copiez ce lien :<br><span style="color:#b7ff00;word-break:break-all">' . $safeUrl . '</span></td></tr>'
      . '</table><div style="padding:16px;color:#6f796d;font-size:11px;text-align:center">PASS50 · Qui dit quoi, qui va où ?</div>'
      . '</td></tr></table></body></html>';
}

function send_brevo_confirmation(string $email, string $displayName, string $confirmationUrl): void {
    $html = pass50_email_template(
        'Confirmez votre inscription PASS50.',
        'Bienvenue sur PASS50',
        'Bonjour <strong>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</strong>, confirmez votre adresse e-mail pour activer vos favoris, vos suivis, vos votes et vos notifications.',
        'Confirmer mon compte',
        $confirmationUrl,
        'Ce lien de confirmation expire automatiquement. Vous n’avez rien demandé ? Ignorez simplement cet e-mail.'
    );
    send_brevo_email($email, $displayName, 'Confirmez votre inscription PASS50', $html);
}

function send_brevo_password_reset(string $email, string $displayName, string $resetUrl): void {
    $html = pass50_email_template(
        'Réinitialisez votre mot de passe PASS50.',
        'Nouveau mot de passe',
        'Une demande de réinitialisation a été reçue pour votre compte PASS50. Utilisez le bouton ci-dessous pour choisir un nouveau mot de passe.',
        'Changer mon mot de passe',
        $resetUrl,
        'Ce lien est valable pendant une durée limitée. Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.'
    );
    send_brevo_email($email, $displayName, 'Réinitialisez votre mot de passe PASS50', $html);
}
