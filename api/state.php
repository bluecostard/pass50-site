<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD']==='GET') {
    $stmt=db()->query("SELECT data,updated_at FROM app_state WHERE id='public' LIMIT 1");
    $row=$stmt->fetch();$data=$row?json_decode((string)$row['data'],true):null;
    json_response(['ok'=>true,'data'=>$data,'stateRevision'=>(int)($data['stateRevision']??0),'updatedAt'=>$row['updated_at']??null]);
}
require_method('POST');
$u=auth_user();require_role($u,'owner','admin');
$in=json_input();
$data=$in['data']??null;
if(!is_array($data)) json_response(['error'=>'État invalide.'],422);
$baseRevision=max(0,(int)($in['baseRevision']??0));$pdo=db();$pdo->beginTransaction();
try{
    $stmt=$pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1 FOR UPDATE");
    $raw=$stmt->fetchColumn();$current=$raw?json_decode((string)$raw,true):[];
    if(!is_array($current))$current=[];
    $currentRevision=max(0,(int)($current['stateRevision']??0));
    if($raw&&$baseRevision<$currentRevision){
        $pdo->rollBack();
        json_response(['error'=>'État obsolète : rechargez la version publique avant de synchroniser.','code'=>'stale_state','stateRevision'=>$currentRevision],409);
    }
    $incoming=$data;$incoming['stateRevision']=$currentRevision;
    $currentComparable=$current;$currentComparable['stateRevision']=$currentRevision;
    if($raw&&json_encode($incoming,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)===json_encode($currentComparable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)){
        $pdo->commit();
        json_response(['ok'=>true,'unchanged'=>true,'stateRevision'=>$currentRevision]);
    }
    $nextRevision=$currentRevision+1;$data['stateRevision']=$nextRevision;
    $stmt=$pdo->prepare("INSERT INTO app_state(id,data,updated_by) VALUES('public',?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_by=VALUES(updated_by),updated_at=NOW()");
    $stmt->execute([json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$u['id']]);
    $pdo->commit();
    json_response(['ok'=>true,'stateRevision'=>$nextRevision]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
}
