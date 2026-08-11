<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/data-engine-core.php';

const P50_DOLPHO_RECOVERY_VERSION='DOLPHO-PROFILE-RECOVERY-V1.0';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>32768)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled']||strlen($secret)<32)json_response(['error'=>'Cron non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$pdo=db();p50_de_ensure_schema();$pdo->beginTransaction();
try{
    $state=p50_de_load_public_state_for_update();if(!$state)throw new RuntimeException('État public introuvable.');
    $profileIndex=-1;
    foreach((array)($state['profiles']??[]) as $index=>$candidate){
        if(strtolower(trim((string)($candidate['id']??'')))==='dolpho'||strtolower(trim((string)($candidate['name']??'')))==='dolpho'){$profileIndex=(int)$index;break;}
    }
    if($profileIndex<0)throw new RuntimeException('Fiche Dolpho introuvable.');
    $profile=&$state['profiles'][$profileIndex];$profileId=(string)$profile['id'];$candidates=[];
    $put=static function(array &$target,string $platform,string $url,string $at,string $source): void {
        if($platform===''||$url==='')return;
        if(isset($target[$platform])&&strcmp((string)$target[$platform]['at'],$at)>=0)return;
        $target[$platform]=['url'=>$url,'at'=>$at,'source'=>$source];
    };
    $stmt=$pdo->prepare("SELECT platform,normalized_url,fetched_at FROM p50_social_link_evidence WHERE profile_id=? AND source_type IN ('manual_owner','manual_admin','manual_candidate') ORDER BY fetched_at DESC,id DESC");
    $stmt->execute([$profileId]);foreach($stmt->fetchAll() as $row)$put($candidates,(string)$row['platform'],(string)$row['normalized_url'],(string)$row['fetched_at'],'evidence');
    $stmt=$pdo->prepare("SELECT platform,new_url,created_at FROM p50_social_link_audit WHERE profile_id=? AND action_type IN ('save','confirm','restore','bulk_save','integrity_restore') AND new_url IS NOT NULL AND new_url<>'' ORDER BY created_at DESC,id DESC");
    $stmt->execute([$profileId]);foreach($stmt->fetchAll() as $row)$put($candidates,(string)$row['platform'],(string)$row['new_url'],(string)$row['created_at'],'audit');
    foreach((array)($profile['links']??[]) as $platform=>$url)$put($candidates,(string)$platform,(string)$url,'','state');
    if(!$candidates)throw new RuntimeException('Aucune ancienne saisie Dolpho retrouvée dans les sauvegardes PASS50.');
    $profile['links']=is_array($profile['links']??null)?$profile['links']:[];$profile['linkChecks']=is_array($profile['linkChecks']??null)?$profile['linkChecks']:[];$profile['platforms']=is_array($profile['platforms']??null)?$profile['platforms']:[];
    $restored=[];$skipped=[];
    foreach($candidates as $platform=>$candidate){
        $normalized=p50_de_normalize_social_url($platform,(string)$candidate['url']);
        if($normalized===''||!p50_platform_host_ok($platform,$normalized)||!p50_de_direct_social_path($platform,$normalized)){$skipped[]=['platform'=>$platform,'url'=>$candidate['url'],'reason'=>'Lien non direct ou plateforme incorrecte'];continue;}
        $delete=$pdo->prepare("DELETE FROM p50_social_link_evidence WHERE profile_id=? AND platform=? AND source_type IN ('manual_owner','manual_admin')");$delete->execute([$profileId,$platform]);
        $validation=['ok'=>true,'status'=>'owner_verified','normalizedUrl'=>$normalized,'httpStatus'=>0,'nameScore'=>100,'message'=>'Lien restauré depuis les sauvegardes PASS50 puis confirmé par le propriétaire'];
        p50_de_add_social_evidence($profileId,$platform,$normalized,'manual_owner','Propriétaire PASS50','',100,$validation);
        p50_de_log_social_action($profileId,$platform,'restore',(string)($profile['links'][$platform]??''),$normalized,['id'=>'owner-pass50','role'=>'owner','display_name'=>'Propriétaire PASS50'],['source'=>$candidate['source'],'permanentValidation'=>true,'version'=>P50_DOLPHO_RECOVERY_VERSION]);
        $profile['links'][$platform]=$normalized;$profile['linkChecks'][$platform]=['status'=>'owner_verified','checkedAt'=>gmdate(DATE_ATOM),'message'=>'Lien restauré depuis l’historique serveur et protégé','persistedServerSide'=>true,'protectedBy'=>'PASS50-STATE-LINK-PROTECTION-V4.1','restoredFrom'=>$candidate['source']];$profile['platforms'][]=$platform;
        $restored[$platform]=['url'=>$normalized,'source'=>$candidate['source']];
    }
    if(!$restored)throw new RuntimeException('Les anciennes saisies retrouvées ne contiennent aucun lien de profil direct valide.');
    $profile['platforms']=array_values(array_unique(array_map('strval',$profile['platforms'])));$profile['officialLinksValidatedAt']=gmdate(DATE_ATOM);$profile['officialLinksValidationVersion']=P50_DOLPHO_RECOVERY_VERSION;
    $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;$state['dolphoProfileRecovery']=['version'=>P50_DOLPHO_RECOVERY_VERSION,'profileId'=>$profileId,'updatedAt'=>gmdate(DATE_ATOM),'platforms'=>array_keys($restored)];
    p50_de_save_public_state($state,'owner-pass50',false);$pdo->commit();
    json_response(['ok'=>true,'version'=>P50_DOLPHO_RECOVERY_VERSION,'dispatchId'=>$dispatchId,'profileId'=>$profileId,'restored'=>$restored,'restoredCount'=>count($restored),'skipped'=>$skipped,'publicStateRevision'=>(int)$state['stateRevision'],'publicStateWrites'=>1]);
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>'Récupération Dolpho interrompue.','detail'=>$error->getMessage()],500);}
