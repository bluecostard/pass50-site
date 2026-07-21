<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
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
$stmt=db()->prepare('INSERT INTO coules_votes(poll_key,user_id,profile_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),updated_at=NOW()');$stmt->execute([$poll,$u['id'],$profile]);
json_response(['ok'=>true]);
