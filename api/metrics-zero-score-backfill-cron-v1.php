<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-zero-score-backfill-core.php';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled']||strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
if(array_diff(array_keys($input),['action','dispatchId','iteration','confirm','period']))json_response(['error'=>'Paramètre interdit.'],422);
$action=(string)($input['action']??'');$dispatchId=substr(trim((string)($input['dispatchId']??'')),0,120);
if($dispatchId===''||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);
try{
    $pdo=db();
    $result=match($action){
      'probe'=>['ok'=>true,'version'=>P50_MZB_VERSION,'dispatchId'=>$dispatchId,'zeroProfiles'=>count(p50_mzb_zero_profile_ids(p50_mzb_state($pdo))),'publicStateWrites'=>0],
      'dispatch'=>p50_mzb_dispatch($pdo,$dispatchId),
      'work'=>p50_mzb_work($pdo,$dispatchId),
      'calculate'=>p50_mzb_calculate($pdo,$dispatchId,(string)($input['period']??'')),
      'apply'=>!empty($input['confirm'])?p50_mzb_apply($pdo,$dispatchId):throw new InvalidArgumentException('Confirmation requise.'),
      default=>throw new InvalidArgumentException('Action inconnue.'),
    };
    json_response($result);
}catch(InvalidArgumentException $error){json_response(['error'=>p50_metrics_safe_error($error->getMessage())],422);}
catch(Throwable $error){error_log('PASS50 zero score backfill: '.p50_mr_safe_error($error));json_response(['error'=>'Rattrapage des scores interrompu.','detail'=>p50_mr_safe_error($error)],500);}
