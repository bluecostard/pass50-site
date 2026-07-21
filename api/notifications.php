<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$u=auth_user();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $stmt=db()->prepare('SELECT id,title,body,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100');$stmt->execute([$u['id']]);
 $items=array_map(fn($n)=>['id'=>(string)$n['id'],'userId'=>$u['id'],'title'=>$n['title'],'body'=>$n['body'],'read'=>(bool)$n['is_read'],'createdAt'=>strtotime($n['created_at'])*1000],$stmt->fetchAll());
 json_response(['ok'=>true,'items'=>$items]);
}
require_method('POST');require_role($u,'owner','admin');$in=json_input();$userId=(string)($in['userId']??'');$title=trim((string)($in['title']??''));$body=trim((string)($in['body']??''));
if($userId===''||$title===''||$body==='')json_response(['error'=>'Notification invalide.'],422);
db()->prepare('INSERT INTO notifications(user_id,title,body) VALUES(?,?,?)')->execute([$userId,$title,$body]);json_response(['ok'=>true],201);
