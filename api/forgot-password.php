<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$in=json_input(); $email=strtolower(trim((string)($in['email']??'')));
if(!filter_var($email,FILTER_VALIDATE_EMAIL)) json_response(['ok'=>true,'message'=>'Si ce compte existe, un e-mail a été envoyé.']);
$stmt=db()->prepare('SELECT id,display_name,email_confirmed_at FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$email]); $u=$stmt->fetch();
if($u && $u['email_confirmed_at']!==null){
  $raw=bin2hex(random_bytes(32)); $hours=max(1,(int)($config['app']['reset_hours']??1));
  $expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$hours.' hours')->format('Y-m-d H:i:s');
  db()->prepare('UPDATE users SET reset_token_hash=?,reset_expires_at=? WHERE id=?')->execute([hash('sha256',$raw),$expires,$u['id']]);
  $url=rtrim($config['app']['base_url'],'/').'/api/reset-password.php?token='.urlencode($raw);
  try{send_brevo_password_reset($email,$u['display_name'],$url);}catch(Throwable $e){error_log('Reset mail: '.$e->getMessage());}
}
json_response(['ok'=>true,'message'=>'Si ce compte existe, un e-mail a été envoyé.']);
