<?php
declare(strict_types=1);

foreach([
    'metrics-collector-facebook.php','metrics-social-collectors-core.php','metrics-collectors-core.php',
    'metrics-orchestrator-core.php','metrics-queue-core.php','content-freshness-core.php',
] as $runtimeFile){
    $runtimePath=__DIR__.'/'.$runtimeFile;
    clearstatcache(true,$runtimePath);
    if(function_exists('opcache_invalidate'))@opcache_invalidate($runtimePath,true);
}

require __DIR__.'/bootstrap.php';
require __DIR__.'/content-freshness-core.php';
require_once __DIR__.'/metrics-social-collectors-core.php';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$contentType=strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
if(!preg_match('~^application/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$~',$contentType))json_response(['error'=>'Type de contenu refusé.'],415);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>16384)json_response(['error'=>'Corps trop volumineux.'],413);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled'])json_response(['error'=>'Orchestrateur métrique désactivé.'],503);
if(strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
if(!defined('P50_FACEBOOK_COLLECTOR_VERSION')||P50_FACEBOOK_COLLECTOR_VERSION!==P50_CONTENT_FRESHNESS_V4_FACEBOOK_COLLECTOR)json_response(['error'=>'Collecteur Facebook V2 non chargé.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$keys=array_keys($input);sort($keys);if($keys!==['action','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
$action=$input['action']??null;if(!is_string($action)||!in_array($action,['probe','refresh'],true))json_response(['error'=>'Action invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

if($action==='probe')json_response([
    'ok'=>true,'action'=>'probe','dispatchId'=>$dispatchId,'version'=>P50_CONTENT_FRESHNESS_V4_VERSION,
    'bucketSeconds'=>P50_CONTENT_FRESHNESS_V4_BUCKET_SECONDS,'facebookCollectorVersion'=>P50_FACEBOOK_COLLECTOR_VERSION,
    'xFastCycle'=>p50_cf4_x_policy(),'publicStateWrites'=>0,
]);

set_time_limit(280);
$result=p50_cf4_execute(db(),$dispatchId,[
    'mode'=>'cycle',
    'profileLimit'=>P50_CONTENT_FRESHNESS_V4_PROFILE_LIMIT,
    'jobLimit'=>P50_CONTENT_FRESHNESS_V4_JOB_LIMIT,
    'maxIterations'=>P50_CONTENT_FRESHNESS_V4_WORK_ITERATIONS,
    'forceTopN'=>P50_CONTENT_FRESHNESS_V4_TOP_RANK_FORCE,
]);
if(empty($result['ok']))json_response($result,500);
$result['action']='refresh';
json_response($result);
