<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';
const P50_PROFILE_PURGE_CONTRACT='P50-PROFILE-PURGE-V1.0';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mrp_apply_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['orchestratorEnabled']||strlen($secret)<32)json_response(['error'=>'Orchestrateur non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);$action=(string)($input['action']??'');$dispatchId=trim((string)($input['dispatchId']??''));
if(!in_array($action,['probe','purge'],true)||$dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'Requête invalide.'],422);
if($action==='probe')json_response(['ok'=>true,'contract'=>P50_PROFILE_PURGE_CONTRACT,'action'=>'probe','readOnly'=>true]);
function p50_purge_state_value(mixed $value,array $ids,string $context=''): mixed {
 if(!is_array($value))return $value;$list=array_is_list($value);$result=[];
 foreach($value as $key=>$item){
  if(isset($ids[(string)$key]))continue;
  if(is_array($item)){
   $refs=[(string)($item['profileId']??''),(string)($item['profile_id']??''),(string)($item['influencerId']??''),(string)($item['candidateId']??'')];
   if(array_filter($refs,static fn(string $id):bool=>$id!==''&&isset($ids[$id])))continue;
   $itemId=(string)($item['id']??'');if($context==='profiles'&&$itemId!==''&&isset($ids[$itemId]))continue;
  }
  $clean=p50_purge_state_value($item,$ids,(string)$key);if($list)$result[]=$clean;else$result[$key]=$clean;
 }return $result;
}
$pdo=db();$pdo->exec("CREATE TABLE IF NOT EXISTS p50_profile_purge_backups (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,dispatch_id VARCHAR(120) NOT NULL,removed_profiles_json LONGTEXT NOT NULL,state_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_p50_profile_purge_dispatch(dispatch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$pdo->beginTransaction();
try{
 $stmt=$pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1 FOR UPDATE");$rawState=$stmt->fetchColumn();
 $state=$rawState?json_decode((string)$rawState,true):null;if(!is_array($state))throw new RuntimeException('État public invalide.');
 $removed=[];foreach((array)($state['profiles']??[]) as $profile)if(is_array($profile)&&(!empty($profile['adminDeleted'])||(array_key_exists('alive',$profile)&&$profile['alive']===false))){$id=trim((string)($profile['id']??''));if($id!=='')$removed[$id]=(string)($profile['name']??$id);}
 if(!$removed){$pdo->commit();json_response(['ok'=>true,'contract'=>P50_PROFILE_PURGE_CONTRACT,'action'=>'purge','purgedCount'=>0,'purgedProfiles'=>[],'stateRevision'=>(int)($state['stateRevision']??0)]);}
 
 $pdo->prepare('INSERT INTO p50_profile_purge_backups(dispatch_id,removed_profiles_json,state_json) VALUES(?,?,?)')->execute([$dispatchId,json_encode($removed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rawState]);
 $clean=p50_purge_state_value($state,array_fill_keys(array_keys($removed),true));$clean['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;$clean['publishedAt']=gmdate('c');
 $pdo->prepare("UPDATE app_state SET data=?,updated_by=NULL,updated_at=NOW() WHERE id='public'")->execute([json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
 $pdo->commit();$items=[];foreach($removed as $id=>$name)$items[]=['id'=>$id,'name'=>$name];
 json_response(['ok'=>true,'contract'=>P50_PROFILE_PURGE_CONTRACT,'action'=>'purge','purgedCount'=>count($items),'purgedProfiles'=>$items,'stateRevision'=>$clean['stateRevision']]);
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();error_log('profile purge: '.$error->getMessage());json_response(['error'=>'La purge des profils a échoué.','detail'=>mb_substr($error->getMessage(),0,300)],500);}
