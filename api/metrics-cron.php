<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>16384)json_response(['error'=>'Corps trop volumineux.'],413);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=$cfg['cronSecret'];if(strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
if(array_diff(array_keys($input),['action','cadence','dispatchId','iteration']))json_response(['error'=>'Paramètre interdit.'],422);
$action=(string)($input['action']??'');$cadence=(string)($input['cadence']??'');$dispatchId=substr(trim((string)($input['dispatchId']??'')),0,120);
if(!in_array($action,['dispatch','work'],true))json_response(['error'=>'Action inconnue.'],422);
$iteration=$input['iteration']??null;if($action==='work'&&(!is_int($iteration)||$iteration<1||$iteration>500))json_response(['error'=>'Itération invalide.'],422);
try{$cadenceConfig=p50_mo_cadence($cadence);}catch(Throwable){json_response(['error'=>'Cadence inconnue.'],422);}
if(!$cfg['enabled'])json_response(['error'=>'Orchestrateur métrique désactivé.'],503);if($dispatchId==='')json_response(['error'=>'dispatchId obligatoire.'],422);
$started=microtime(true);
try{
    if($action==='dispatch'){$run=p50_mo_dispatch(db(),$cadence,$dispatchId,['source'=>'cron_hmac']);$response=['ok'=>true,'cadence'=>$cadence,'dispatchId'=>$dispatchId,'enqueued'=>$run['enqueued'],'processed'=>0,'completed'=>0,'partial'=>0,'retried'=>0,'skipped'=>0,'failed'=>0,'remaining'=>$run['remaining']];}
    else{$work=p50_metrics_process_next_job(db());$response=['ok'=>true,'cadence'=>$cadence,'dispatchId'=>$dispatchId,'enqueued'=>0,'processed'=>$work['processed'],'completed'=>$work['completed']??0,'partial'=>$work['partial']??0,'retried'=>$work['retried']??0,'skipped'=>$work['skipped']??0,'failed'=>$work['failed']??0,'remaining'=>$work['remaining']];}
    $response['durationMs']=(int)round((microtime(true)-$started)*1000);json_response($response);
}catch(Throwable $error){error_log('PASS50 metrics cron: '.p50_metrics_safe_error($error->getMessage()));json_response(['error'=>'Exécution métrique interrompue.'],500);}
