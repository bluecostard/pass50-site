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

require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');

$raw=file_get_contents('php://input');
$input=is_array(json_decode($raw?:'',true))?json_decode($raw,true):[];
$mode=trim((string)($input['mode']??'collect_all'));
if(!in_array($mode,['sync_only','collect_all','collect_work'],true))json_response(['error'=>'Mode invalide.'],422);

$pdo=db();
p50_metrics_ensure_schema($pdo);

if($mode==='sync_only'){
    $refresh=p50_ci_refresh($pdo);
    json_response(['ok'=>true,'mode'=>'sync_only','contentIntelligence'=>$refresh,'publicStateWrites'=>0,'continue'=>false,'remaining'=>0]);
}

if(!defined('P50_FACEBOOK_COLLECTOR_VERSION')||P50_FACEBOOK_COLLECTOR_VERSION!==P50_CONTENT_FRESHNESS_V4_FACEBOOK_COLLECTOR){
    json_response(['error'=>'Collecteur Facebook V2 non chargé.'],503);
}

// Lots courts : les proxies IONOS coupent souvent les POST > 30–60 s.
set_time_limit(90);
$timeBudgetMs=max(8000,min(25000,(int)($input['timeBudgetMs']??20000)));
$maxIterations=max(8,min(60,(int)($input['maxIterations']??36)));
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||!preg_match('/^[A-Za-z0-9._-]{8,80}$/',$dispatchId)){
    $dispatchId='admin-'.preg_replace('/[^A-Za-z0-9._-]/','',(string)($user['id']??'user')).'-'.time();
}

if($mode==='collect_work'){
    $result=p50_cf4_execute($pdo,$dispatchId,[
        'mode'=>'work',
        'maxIterations'=>$maxIterations,
        'timeBudgetMs'=>$timeBudgetMs,
        'forceRefresh'=>!empty($input['forceRefresh']),
        'enqueueReason'=>'content_freshness_admin_work',
    ]);
}else{
    $result=p50_cf4_execute($pdo,$dispatchId,[
        'mode'=>'all',
        'rankedLimit'=>70,
        'jobLimit'=>140,
        'maxIterations'=>$maxIterations,
        'timeBudgetMs'=>$timeBudgetMs,
        'forceTopN'=>P50_CONTENT_FRESHNESS_V4_TOP_RANK_FORCE,
        'forceRefresh'=>false,
        'enqueueReason'=>'content_freshness_admin_all',
    ]);
}

if(empty($result['ok']))json_response($result,500);
$result['mode']=$mode;
$result['continue']=!empty($result['continue'])||(int)($result['remaining']??0)>0;
json_response($result);
