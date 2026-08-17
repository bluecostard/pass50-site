<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/data-engine-core.php';

const P50_PROFILE_CLEANUP_VERSION='PROFILE-CLEANUP-V2.1';
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

$pdo=db();p50_de_ensure_schema();
$pdo->beginTransaction();
try{
    $state=p50_de_load_public_state_for_update();if(!$state)throw new RuntimeException('État public introuvable.');
    $removed=p50_apply_profile_tombstones($state);
    $remainingDeleted=0;
    $tombstoneIds=p50_tombstone_ids($state);
    $tombstoneMap=array_fill_keys($tombstoneIds,true);
    foreach((array)($state['profiles']??[]) as $profile){
        if(is_array($profile)&&isset($tombstoneMap[p50_normalize_profile_id($profile['id']??'')]))$remainingDeleted++;
    }
    if($tombstoneIds){
        try{
            $placeholders=implode(',',array_fill(0,count($tombstoneIds),'?'));
            $pdo->prepare("UPDATE p50_profile_registry SET alive=0,eligible=0 WHERE profile_id IN ($placeholders)")->execute($tombstoneIds);
        }catch(Throwable $ignored){}
    }
    $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;
    $state['profileCleanup']=[
        'version'=>P50_PROFILE_CLEANUP_VERSION,
        'updatedAt'=>gmdate(DATE_ATOM),
        'deleted'=>$removed,
        'tombstoneIds'=>$tombstoneIds,
    ];
    p50_de_save_public_state($state,null,false);
    $pdo->commit();
    json_response([
        'ok'=>true,
        'version'=>P50_PROFILE_CLEANUP_VERSION,
        'dispatchId'=>$dispatchId,
        'deletedCount'=>count($removed),
        'deleted'=>$removed,
        'tombstoneCount'=>count($tombstoneIds),
        'tombstoneIds'=>$tombstoneIds,
        'remainingDeletedCount'=>$remainingDeleted,
        'profilesRemaining'=>count($state['profiles']),
        'publicStateWrites'=>1,
    ]);
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>'Suppression interrompue.','detail'=>$error->getMessage()],500);}
