<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/intelligence-core.php';
require __DIR__.'/intelligence-dashboard-v2.php';
const P50_INTELLIGENCE_REFRESH_V2='PASS50-INTELLIGENCE-REFRESH-V2.0';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!$cfg['enabled']||strlen($secret)<32)json_response(['error'=>'Cron non configuré.'],503);
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300||!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));if($dispatchId===''||!preg_match('/^[A-Za-z0-9._-]{1,120}$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);
set_time_limit(280);
try{
    p50_de_sync_registry_from_state();$ids=p50_intelligence_current_profile_ids();$deactivated=p50_intelligence_sync_removed_profiles($ids);
    $processed=0;$errors=[];
    foreach(array_keys($ids) as $profileId){
        try{p50_intelligence_run_profile($profileId);$processed++;}
        catch(Throwable $e){$errors[]=['profileId'=>$profileId,'error'=>$e->getMessage()];if(count($errors)>=20)break;}
    }
    $dashboard=p50_intelligence_dashboard_v2();
    json_response(['ok'=>true,'version'=>P50_INTELLIGENCE_REFRESH_V2,'dispatchId'=>$dispatchId,'profilesProcessed'=>$processed,'errors'=>$errors,'registryProfilesDeactivated'=>$deactivated,'dashboard'=>$dashboard]);
}catch(Throwable $e){json_response(['error'=>'Refresh Intelligence interrompu.','detail'=>$e->getMessage()],500);}
