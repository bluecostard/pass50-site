<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$u=auth_user();
$pdo=db();$pdo->beginTransaction();
try{
 $pdo->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$u['id']]);
 $pdo->prepare('UPDATE users SET email=CONCAT("deleted+",id,"@invalid.local"),display_name="Compte supprimé",password_hash="!",deleted_at=NOW() WHERE id=?')->execute([$u['id']]);
 $pdo->commit();json_response(['ok'=>true]);
}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Suppression impossible.'],500);}
