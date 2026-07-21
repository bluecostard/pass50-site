<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD']==='GET') {
    $stmt=db()->query("SELECT data FROM app_state WHERE id='public' LIMIT 1");
    $raw=$stmt->fetchColumn();
    json_response(['ok'=>true,'data'=>$raw?json_decode($raw,true):null]);
}
require_method('POST');
$u=auth_user();require_role($u,'owner','admin');
$in=json_input();
$data=$in['data']??null;
if(!is_array($data)) json_response(['error'=>'État invalide.'],422);
$stmt=db()->prepare("INSERT INTO app_state(id,data,updated_by) VALUES('public',?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_by=VALUES(updated_by),updated_at=NOW()");
$stmt->execute([json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$u['id']]);
json_response(['ok'=>true]);
