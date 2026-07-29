<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';

require_method('GET');
$code=strtolower(trim((string)($_GET['code']??'')));
if(!preg_match('/^[a-f0-9]{32}$/',$code))json_response(['error'=>'Code de confirmation invalide.'],400);
p50mo_ensure_schema();
$stmt=db()->prepare('SELECT confirmation_code,status,requested_at,completed_at FROM p50_meta_deletion_requests WHERE confirmation_code=? LIMIT 1');
$stmt->execute([$code]);$row=$stmt->fetch();
if(!is_array($row))json_response(['ok'=>true,'found'=>false]);
json_response([
    'ok'=>true,
    'found'=>true,
    'confirmationCode'=>(string)$row['confirmation_code'],
    'status'=>(string)$row['status'],
    'requestedAt'=>$row['requested_at']?(string)$row['requested_at'].'Z':null,
    'completedAt'=>$row['completed_at']?(string)$row['completed_at'].'Z':null,
]);
