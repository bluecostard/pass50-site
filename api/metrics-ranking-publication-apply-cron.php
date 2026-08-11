<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$contentType=strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
if(!preg_match('~^application/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$~',$contentType))json_response(['error'=>'Type de contenu refusé.'],415);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>16384)json_response(['error'=>'Corps trop volumineux.'],413);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);

$cfg=p50_mrp_apply_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['orchestratorEnabled'])json_response(['error'=>'Orchestrateur métrique désactivé.'],503);
if(strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);

$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$action=$input['action']??null;if(!is_string($action)||!in_array($action,['probe','preview','apply'],true))json_response(['error'=>'Action invalide.'],422);
if(!is_string($input['dispatchId']??null))json_response(['error'=>'dispatchId invalide.'],422);
$dispatchId=trim($input['dispatchId']);if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);
$keys=array_keys($input);sort($keys);

if($action==='probe'){
    if($keys!==['action','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
    $health=null;
    try{$pdo=db();p50_mrp_apply_ensure_schema($pdo);$health=p50_mrp_apply_health($pdo);}catch(Throwable){$health=null;}
    json_response([
        'ok'=>true,'action'=>'probe','dispatchId'=>$dispatchId,
        'contract'=>P50_MRP_APPLY_VERSION,
        'publicationEnabled'=>$cfg['publicationEnabled'],
        'automaticPublicationEnabled'=>$cfg['automaticPublicationEnabled'],
        'bootstrapAllowed'=>$cfg['bootstrapAllowed'],
        'forcedBootstrapEnabled'=>false,
        'health'=>$health,
    ]);
}

$started=microtime(true);
try{
    $pdo=db();
    if($action==='preview'){
        if($keys!==['action','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
        $preview=p50_mrp_apply_preview($pdo);
        $preview['ok']=true;$preview['dispatchId']=$dispatchId;
        $preview['forcedBootstrapEnabled']=false;
        $preview['durationMs']=(int)round((microtime(true)-$started)*1000);
        json_response($preview);
    }
    // Après consommation du recovery unique, le cron accepte uniquement l’application standard.
    if($keys!==['action','confirm','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
    if(empty($input['confirm']))json_response(['error'=>'Confirmation requise.'],422);
    if(!$cfg['automaticPublicationEnabled'])json_response(['error'=>'Publication automatique désactivée.','skipped'=>true,'reason'=>'automatic_disabled'],200);
    $result=p50_mrp_apply_execute($pdo,[
        'mode'=>'automatic',
        'dispatchId'=>$dispatchId,
        'appliedBy'=>'cron-automatic',
        'confirm'=>true,
        'bootstrap'=>false,
    ]);
    $result['durationMs']=(int)round((microtime(true)-$started)*1000);
    json_response($result);
}catch(Throwable $error){
    error_log('PASS50 publication apply cron: '.p50_mr_safe_error($error));
    $msg=$error->getMessage();
    // Soft-skip when gates block — avoids failing the workflow every cycle.
    if(str_contains($msg,'Garde-fous')||str_contains($msg,'Aucune mutation')){
        json_response([
            'ok'=>true,'skipped'=>true,'reason'=>'gates_or_empty',
            'error'=>$msg,'dispatchId'=>$dispatchId,
            'publicStateWrites'=>0,
            'durationMs'=>(int)round((microtime(true)-$started)*1000),
        ]);
    }
    json_response(['error'=>'Publication automatique interrompue.','detail'=>p50_mr_safe_error($error)],500);
}
