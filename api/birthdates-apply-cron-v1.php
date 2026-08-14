<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';
const P50_BIRTH_APPLY_CONTRACT='P50-BIRTH-APPLY-V1.0';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');$cfg=p50_mrp_apply_config();$secret=(string)$cfg['cronSecret'];
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!$cfg['orchestratorEnabled']||strlen($secret)<32||!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300||!p50_mo_verify_cron_signature($secret,$timestamp,$raw?:'',$signature))json_response(['error'=>'Authentification refusée.'],401);
$input=json_decode($raw?:'',true);$dispatchId=trim((string)($input['dispatchId']??''));if((string)($input['action']??'')!=='apply'||$dispatchId==='')json_response(['error'=>'Requête invalide.'],422);
$updates=[
 'eunice'=>['birthDate'=>'1995-05-21','birthYear'=>1995,'manual'=>false,'sources'=>[
  'https://www.notrevoix.info/info/articles/eunice-zunon-coup-d-essai-coup-de-maitre',
  'https://www.notrevoix.info/info/articles/apres-l-humour-et-la-musique-eunice-zunon-s-engage-dans-l-entrepreneuriat'
 ]],
 'census-lionel-pcs'=>['birthDate'=>'2003-05-25','birthYear'=>2003,'manual'=>true,'sources'=>[
  'owner-confirmation:2026-08-14',
  'https://fr.wikipedia.org/wiki/Lionel_Pcs'
 ]]
];
$pdo=db();$pdo->exec("CREATE TABLE IF NOT EXISTS p50_birth_apply_backups (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,dispatch_id VARCHAR(120) NOT NULL,state_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_p50_birth_dispatch(dispatch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$pdo->beginTransaction();
try{
 $row=$pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1 FOR UPDATE")->fetchColumn();$state=$row?json_decode((string)$row,true):null;if(!is_array($state))throw new RuntimeException('État invalide.');
 $pdo->prepare('INSERT INTO p50_birth_apply_backups(dispatch_id,state_json) VALUES(?,?)')->execute([$dispatchId,$row]);
 $applied=[];
 foreach($state['profiles'] as &$profile){$id=(string)($profile['id']??'');if(!isset($updates[$id]))continue;$u=$updates[$id];if(trim((string)($profile['birthDate']??''))!=='')continue;
  $profile['birthDate']=$u['birthDate'];$profile['birthYear']=$u['birthYear'];$profile['ageStatus']='confirmed';$profile['agePublic']=true;
  $profile['birthManualLocked']=!empty($u['manual']);$profile['birthEvidence']=['status'=>'confirmed','sources'=>$u['sources'],'checkedAt'=>gmdate('c')];
  $profile['dataEngine']=is_array($profile['dataEngine']??null)?$profile['dataEngine']:[];
  $profile['dataEngine']['verifiedFacts']=array_values(array_unique(array_merge((array)($profile['dataEngine']['verifiedFacts']??[]),['birth_date'])));
  $applied[]=['id'=>$id,'name'=>(string)($profile['name']??$id),'birthDate'=>$u['birthDate']];
 }unset($profile);
 $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;$state['publishedAt']=gmdate('c');
 $pdo->prepare("UPDATE app_state SET data=?,updated_by=NULL,updated_at=NOW() WHERE id='public'")->execute([json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
 $pdo->commit();json_response(['ok'=>true,'contract'=>P50_BIRTH_APPLY_CONTRACT,'applied'=>$applied,'appliedCount'=>count($applied),'stateRevision'=>$state['stateRevision']]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>'Mise à jour refusée.','detail'=>mb_substr($e->getMessage(),0,240)],500);}
