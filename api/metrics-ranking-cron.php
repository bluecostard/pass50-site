<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/metrics-ranking-core.php';
require __DIR__.'/metrics-ranking-fresh-capture-core.php';
require __DIR__.'/metrics-ranking-readiness-core.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$contentType=strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
if(!preg_match('~^application/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$~',$contentType))json_response(['error'=>'Type de contenu refusé.'],415);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length>16384)json_response(['error'=>'Corps trop volumineux.'],413);
$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);

$cfg=p50_mo_config();
$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled'])json_response(['error'=>'Orchestrateur métrique désactivé.'],503);
if(strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);

$input=json_decode($raw,true);
if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$keys=array_keys($input);sort($keys);
if($keys!==['action','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
if(($input['action']??null)!=='calculate')json_response(['error'=>'Action invalide.'],422);
if(!is_string($input['dispatchId']??null))json_response(['error'=>'dispatchId invalide.'],422);
$dispatchId=trim($input['dispatchId']);
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$started=microtime(true);
try{
    $pdo=db();
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $readiness=p50_mrr_readiness($pdo,$now);
    $response=[
        'ok'=>true,'skipped'=>false,'dispatchId'=>$dispatchId,
        'algorithmVersion'=>P50_MR_ALGORITHM_VERSION,'periods'=>array_keys(p50_mr_periods()),
        'freshCaptureGateVersion'=>P50_MR_FRESH_CAPTURE_GATE_VERSION,
        'readiness'=>$readiness,
    ];
    if(empty($readiness['ready'])){
        $response['skipped']=true;
        $response['reason']=(string)($readiness['reason']??'data_not_ready');
        $response['durationMs']=(int)round((microtime(true)-$started)*1000);
        json_response($response);
    }

    $result=p50_mr_calculate_if_due_with_fresh_captures($pdo,$now,90,$dispatchId);
    $response['skipped']=(bool)($result['skipped']??false);
    $response['freshCaptureOverride']=(bool)($result['freshCaptureOverride']??false);
    if(isset($result['latestPreviousFinishedAt']))$response['latestPreviousFinishedAt']=$result['latestPreviousFinishedAt'];
    if(isset($result['latestUsableCaptureRecordedAt']))$response['latestUsableCaptureRecordedAt']=$result['latestUsableCaptureRecordedAt'];
    if($response['skipped']){
        $response['reason']=(string)($result['reason']??'recent_success');
        $response['latestFinishedAt']=$result['latestFinishedAt']??null;
    }else{
        $response['runUuid']=(string)$result['runUuid'];
        $response['classableCount']=(int)$result['classableCount'];
        $response['scoresWritten']=(int)$result['scoresWritten'];
        p50_mrp_apply_clear_preview_cache($pdo);
    }
    $response['durationMs']=(int)round((microtime(true)-$started)*1000);
    json_response($response);
}catch(Throwable $error){
    error_log('PASS50 metrics ranking cron: '.p50_mr_safe_error($error));
    json_response(['error'=>'Calcul expérimental interrompu.'],500);
}
