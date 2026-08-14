<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/duel-history-core.php';
if ($_SERVER['REQUEST_METHOD']==='GET' && (string)($_GET['admin']??'')==='1') {
    $viewer=auth_user();
    if(!in_array((string)($viewer['role']??''),['owner','admin'],true))json_response(['error'=>'Accès réservé à l’administration.'],403);
    $poll=trim((string)($_GET['poll']??''));
    if($poll==='')json_response(['error'=>'Duel manquant.'],422);
    $stmt=db()->prepare("SELECT v.profile_id,v.user_id,v.created_at,v.updated_at,u.display_name,u.email
      FROM coules_votes v LEFT JOIN users u ON u.id=v.user_id
      WHERE v.poll_key=? ORDER BY COALESCE(v.updated_at,v.created_at) DESC");
    $stmt->execute([$poll]);$items=[];$totals=[];
    foreach($stmt->fetchAll() as $row){
        $profileId=(string)$row['profile_id'];$totals[$profileId]=($totals[$profileId]??0)+1;
        $items[]=[
          'profileId'=>$profileId,'userId'=>(string)$row['user_id'],
          'displayName'=>(string)($row['display_name']??''),'email'=>(string)($row['email']??''),
          'votedAt'=>!empty($row['updated_at'])?(string)$row['updated_at']:(string)($row['created_at']??'')
        ];
    }
    json_response(['ok'=>true,'pollKey'=>$poll,'total'=>count($items),'totals'=>$totals,'items'=>$items]);
}
if ($_SERVER['REQUEST_METHOD']==='GET') {
    $poll=trim((string)($_GET['poll']??''));
    if($poll==='') json_response(['error'=>'Sondage manquant.'],422);
    $stmt=db()->prepare('SELECT profile_id,COUNT(*) AS vote_count FROM coules_votes WHERE poll_key=? GROUP BY profile_id');$stmt->execute([$poll]);
    $totals=[];foreach($stmt->fetchAll() as $r)$totals[$r['profile_id']]=(int)$r['vote_count'];
    $mine=null;$u=auth_user(false);if($u){$stmt=db()->prepare('SELECT profile_id FROM coules_votes WHERE poll_key=? AND user_id=?');$stmt->execute([$poll,$u['id']]);$mine=$stmt->fetchColumn()?:null;}
    json_response(['ok'=>true,'totals'=>$totals,'myVote'=>$mine]);
}
require_method('POST');
$u=auth_user();$in=json_input();$poll=trim((string)($in['pollKey']??''));$profile=trim((string)($in['profileId']??''));
if($poll===''||$profile==='')json_response(['error'=>'Vote invalide.'],422);
p50_duel_history_ensure_schema();$pdo=db();$pdo->beginTransaction();
try{
    $stmt=$pdo->prepare('INSERT INTO coules_votes(poll_key,user_id,profile_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),updated_at=NOW()');$stmt->execute([$poll,$u['id'],$profile]);
    $historyId=p50_duel_capture_vote_history((string)$u['id'],$poll,$profile);
    $pdo->commit();json_response(['ok'=>true,'historyId'=>$historyId]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
}
