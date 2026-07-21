<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$in = json_input();
$email = strtolower(trim((string)($in['email'] ?? '')));
$password = (string)($in['password'] ?? '');
$displayName = trim((string)($in['displayName'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error' => 'Adresse e-mail invalide.'], 422);
if (strlen($password) < 8) json_response(['error' => 'Le mot de passe doit contenir au moins 8 caractères.'], 422);
if ($displayName === '' || mb_strlen($displayName) > 40) json_response(['error' => 'Nom d’affichage invalide.'], 422);
$pdo = db();
$stmt = $pdo->prepare('SELECT id,email_confirmed_at FROM users WHERE email=? LIMIT 1');
$stmt->execute([$email]);
$existing = $stmt->fetch();
if ($existing && $existing['email_confirmed_at'] !== null) json_response(['error' => 'Cette adresse est déjà utilisée.'], 409);
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$hours = max(1, (int)$config['app']['confirmation_hours']);
$expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . $hours . ' hours')->format('Y-m-d H:i:s');
try {
    $pdo->beginTransaction();
    if ($existing) {
        $id = $existing['id'];
        $stmt = $pdo->prepare('UPDATE users SET password_hash=?,display_name=?,confirmation_token_hash=?,confirmation_expires_at=?,deleted_at=NULL WHERE id=?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $displayName, $tokenHash, $expires, $id]);
    } else {
        $id = uuid_v4();
        $count = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn();
        $role = $count === 0 ? 'owner' : 'member';
        $stmt = $pdo->prepare('INSERT INTO users(id,email,password_hash,display_name,role,confirmation_token_hash,confirmation_expires_at) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$id, $email, password_hash($password, PASSWORD_DEFAULT), $displayName, $role, $tokenHash, $expires]);
        $pdo->prepare('INSERT INTO user_preferences(user_id,favorites,following,follow_alerts,notification_mode) VALUES(?,?,?,?,?)')->execute([$id,'[]','[]','{}','daily']);
    }
    $pdo->commit();
    $url = rtrim($config['app']['base_url'], '/') . '/api/confirm.php?token=' . urlencode($rawToken);
    send_brevo_confirmation($email, $displayName, $url);
    $response = ['ok' => true, 'confirmationRequired' => true, 'message' => 'E-mail de confirmation envoyé.'];
    if (!empty($config['app']['show_confirmation_link_in_response'])) $response['confirmationUrl'] = $url;
    json_response($response, 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Register: ' . $e->getMessage());
    json_response(['error' => 'Inscription impossible ou e-mail non envoyé. Vérifiez la configuration Brevo.'], 500);
}
