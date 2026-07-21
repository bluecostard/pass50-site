<?php
declare(strict_types=1);
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) { http_response_code(503); exit('Configuration manquante.'); }
$config = require $configFile;
$d = $config['db'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$d['host'],$d['port'],$d['name'],$d['charset']),$d['user'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$token = (string)($_GET['token'] ?? ''); $ok = false;
if ($token !== '') {
  $stmt=$pdo->prepare('SELECT id FROM users WHERE confirmation_token_hash=? AND confirmation_expires_at>NOW() AND deleted_at IS NULL LIMIT 1');
  $stmt->execute([hash('sha256',$token)]); $id=$stmt->fetchColumn();
  if($id){$pdo->prepare('UPDATE users SET email_confirmed_at=NOW(),confirmation_token_hash=NULL,confirmation_expires_at=NULL WHERE id=?')->execute([$id]);$ok=true;}
}
$base=rtrim($config['app']['base_url'],'/'); $home=htmlspecialchars($base,ENT_QUOTES); $logo=htmlspecialchars($base.'/assets/pass50-logo-email.png',ENT_QUOTES);
?><!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirmation PASS50</title><body style="margin:0;background:#050705;color:#f6f8f4;font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh"><main style="width:min(540px,calc(100% - 28px));padding:30px;border:1px solid #293129;border-radius:22px;background:#0d110d;text-align:center;box-sizing:border-box"><img src="<?= $logo ?>" alt="PASS50" style="width:min(390px,100%);height:auto"><h1 style="color:#b7ff00"><?= $ok ? 'Compte confirmé' : 'Lien invalide ou expiré' ?></h1><p style="line-height:1.55;color:#cbd3c8"><?= $ok ? 'Votre compte PASS50 est actif. Vous pouvez maintenant vous connecter.' : 'Demandez un nouvel e-mail de confirmation depuis le site.' ?></p><a href="<?= $home ?>" style="display:inline-block;margin-top:12px;padding:14px 20px;border-radius:12px;background:#b7ff00;color:#050705;text-decoration:none;font-weight:bold">Retour sur PASS50</a></main></body></html>