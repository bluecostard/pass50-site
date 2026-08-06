<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/data-engine-core.php';

const P50_PROFILE_CLEANUP_VERSION='PROFILE-CLEANUP-V1.0';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>32768)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled']||strlen($secret)<32)json_response(['error'=>'Cron non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$deleteIds=[
 'census-henri-michel',
 'census-jai-horreur-des-fautes-lofficiel',
 'census-le-brouteur',
 'census-les-adresses-de-chez-nous',
 'census-oustaz-diakite-yaya',
 'census-sheisthecode',
 'census-simon-adingra',
 'census-epouse-gnahore',
];
$keepId='census-reine-a';
$reineInstagram='https://www.instagram.com/cacaoispoppin/';

$pdo=db();p50_de_ensure_schema();
$pdo->beginTransaction();
try{
    $state=p50_de_load_public_state_for_update();if(!$state)throw new RuntimeException('État public introuvable.');
    $profiles=is_array($state['profiles']??null)?$state['profiles']:[];
    $deleted=[];$keptUpdated=false;
    $state['profiles']=array_values(array_filter($profiles,function($profile)use($deleteIds,&$deleted,$keepId,$reineInstagram,&$keptUpdated){
        if(!is_array($profile)||empty($profile['id']))return true;
        $id=(string)$profile['id'];
        if(in_array($id,$deleteIds,true)){$deleted[]=['id'=>$id,'name'=>(string)($profile['name']??$id)];return false;}
        return true;
    }));
    foreach($state['profiles'] as &$profile){
        if((string)($profile['id']??'')!==$keepId)continue;
        $profile['links']=is_array($profile['links']??null)?$profile['links']:[];
        $profile['linkChecks']=is_array($profile['linkChecks']??null)?$profile['linkChecks']:[];
        $profile['links']['Instagram']=$reineInstagram;
        $profile['linkChecks']['Instagram']=['status'=>'owner_verified','checkedAt'=>gmdate(DATE_ATOM),'message'=>'Compte public identifié : @cacaoispoppin','persistedServerSide'=>true];
        $profile['platforms']=array_values(array_unique(array_merge((array)($profile['platforms']??[]),['Instagram'])));
        $keptUpdated=true;
    }
    unset($profile);
    foreach(['content','events','signals','liveStreams'] as $key){
        if(!is_array($state[$key]??null))continue;
        $state[$key]=array_values(array_filter($state[$key],static fn($row)=>!is_array($row)||!in_array((string)($row['profileId']??''),$deleteIds,true)));
    }
    $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;
    $state['profileCleanup']=['version'=>P50_PROFILE_CLEANUP_VERSION,'updatedAt'=>gmdate(DATE_ATOM),'deleted'=>$deleted,'keptAndCompleted'=>['id'=>$keepId,'name'=>'Reine A.','Instagram'=>$reineInstagram]];
    p50_de_save_public_state($state,null,false);
    $pdo->commit();
    json_response(['ok'=>true,'version'=>P50_PROFILE_CLEANUP_VERSION,'dispatchId'=>$dispatchId,'deletedCount'=>count($deleted),'deleted'=>$deleted,'keptUpdated'=>$keptUpdated,'kept'=>['id'=>$keepId,'name'=>'Reine A.','Instagram'=>$reineInstagram],'profilesRemaining'=>count($state['profiles']),'publicStateWrites'=>1]);
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>'Suppression interrompue.','detail'=>$error->getMessage()],500);}
