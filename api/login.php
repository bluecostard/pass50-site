<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$in = json_input();
$email = strtolower(trim((string)($in['email'] ?? '')));
$password = (string)($in['password'] ?? '');
$stmt = db()->prepare('SELECT * FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$email]);
$u = $stmt->fetch();
if (!$u || !password_verify($password, $u['password_hash'])) json_response(['error' => 'Identifiants incorrects.'], 401);
if ($u['email_confirmed_at'] === null) json_response(['error' => 'Confirmez d’abord votre adresse e-mail.'], 403);
$token = create_session($u['id']);
json_response(['ok' => true, 'token' => $token, 'user' => user_payload($u)]);
