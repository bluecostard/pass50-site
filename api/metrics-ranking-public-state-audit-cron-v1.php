<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';

const P50_MR_PUBLIC_STATE_AUDIT_CONTRACT='MR-PUBLIC-STATE-AUDIT-V1.0';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$contentType=strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
if(!preg_match('~^application/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$~',$contentType))json_response(['error'=>'Type de contenu refusé.'],415);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length>16384)json_response(['error'=>'Corps trop volumineux.'],413);
$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);

$cfg=p50_mrp_apply_config();
$secret=(string)$cfg['cronSecret'];
if(!$cfg['orchestratorEnabled'])json_response(['error'=>'Orchestrateur métrique désactivé.'],503);
if(strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);

$input=json_decode($raw,true);
if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$keys=array_keys($input);sort($keys);
if($keys!==['action','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
$action=$input['action']??null;
if(!is_string($action)||!in_array($action,['probe','audit'],true))json_response(['error'=>'Action invalide.'],422);
if(!is_string($input['dispatchId']??null))json_response(['error'=>'dispatchId invalide.'],422);
$dispatchId=trim($input['dispatchId']);
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$started=microtime(true);
try{
    $pdo=db();
    if($action==='probe')json_response([
        'ok'=>true,
        'action'=>'probe',
        'contract'=>P50_MR_PUBLIC_STATE_AUDIT_CONTRACT,
        'dispatchId'=>$dispatchId,
        'readOnly'=>true,
        'publicStateWrites'=>0,
    ]);

    $stmt=$pdo->query("SELECT data,updated_at FROM app_state WHERE id='public' LIMIT 1");
    $row=$stmt->fetch();
    if(!$row)json_response(['error'=>'État public introuvable.'],404);
    $state=json_decode((string)$row['data'],true);
    if(!is_array($state))json_response(['error'=>'État public invalide.'],500);

    $profiles=(array)($state['profiles']??[]);
    $profileStats=[
        'total'=>0,'alive'=>0,'eligible'=>0,'classable'=>0,
        'primaryScore'=>0,'autoScore'=>0,
    ];
    $runReferences=[];$scoreStatuses=[];
    foreach($profiles as $profile){
        if(!is_array($profile))continue;
        $profileStats['total']++;
        $alive=!array_key_exists('alive',$profile)||!empty($profile['alive']);
        $eligible=!empty($profile['eligible']);
        $classable=!array_key_exists('classable',$profile)||$profile['classable']!==false;
        if($alive)$profileStats['alive']++;
        if($alive&&$eligible)$profileStats['eligible']++;
        if($alive&&$eligible&&$classable)$profileStats['classable']++;
        if(isset($profile['score'])&&is_numeric($profile['score']))$profileStats['primaryScore']++;
        $engine=is_array($profile['dataEngine']??null)?$profile['dataEngine']:[];
        if(!empty($engine['autoScore']))$profileStats['autoScore']++;
        $runUuid=trim((string)($engine['metricRankingRunUuid']??''));
        if($runUuid!=='')$runReferences[$runUuid]=($runReferences[$runUuid]??0)+1;
        $scoreStatus=trim((string)($engine['scoreStatus']??''));
        if($scoreStatus!=='')$scoreStatuses[$scoreStatus]=($scoreStatuses[$scoreStatus]??0)+1;
    }
    arsort($runReferences);ksort($scoreStatuses);

    $periods=[];
    foreach(array_keys(p50_mr_periods()) as $period){
        $publicRows=p50_mrp_public_rows($state,$period)['rows'];
        $scores=array_map(static fn(array $item): float=>(float)$item['score'],$publicRows);
        $periods[$period]=[
            'rankableCount'=>count($publicRows),
            'topScore'=>$scores?max($scores):null,
            'lowestScore'=>$scores?min($scores):null,
        ];
    }

    $meta=is_array($state['metricsRankingMeta']??null)?$state['metricsRankingMeta']:[];
    $metaSafe=[
        'version'=>trim((string)($meta['version']??''))?:null,
        'algorithmVersion'=>trim((string)($meta['algorithmVersion']??''))?:null,
        'runUuid'=>trim((string)($meta['runUuid']??''))?:null,
        'publishedAt'=>trim((string)($meta['publishedAt']??''))?:null,
        'periods'=>array_values(array_filter(array_map('strval',(array)($meta['periods']??[])))),
    ];

    $latestApplied=null;
    if(p50_metrics_table_exists($pdo,'p50_metric_publication_applies')){
        $apply=$pdo->query("SELECT id,apply_uuid,mode,status,run_uuid,public_revision_before,public_revision_after,
            profiles_updated,scores_written,entries_count,exits_count,bootstrap,generated_at
          FROM p50_metric_publication_applies WHERE status='applied' ORDER BY id DESC LIMIT 1")->fetch();
        if(is_array($apply))$latestApplied=[
            'id'=>(int)$apply['id'],
            'applyUuid'=>(string)$apply['apply_uuid'],
            'mode'=>(string)$apply['mode'],
            'runUuid'=>(string)$apply['run_uuid'],
            'publicRevisionBefore'=>(int)$apply['public_revision_before'],
            'publicRevisionAfter'=>(int)$apply['public_revision_after'],
            'profilesUpdated'=>(int)$apply['profiles_updated'],
            'scoresWritten'=>(int)$apply['scores_written'],
            'entries'=>(int)$apply['entries_count'],
            'exits'=>(int)$apply['exits_count'],
            'bootstrap'=>(bool)$apply['bootstrap'],
            'generatedAt'=>(string)$apply['generated_at'],
        ];
    }

    $stateRevision=(int)($state['stateRevision']??0);
    $latestRun=(string)($latestApplied['runUuid']??'');
    $metaRun=(string)($metaSafe['runUuid']??'');
    $latestAfter=(int)($latestApplied['publicRevisionAfter']??0);

    json_response([
        'ok'=>true,
        'action'=>'audit',
        'contract'=>P50_MR_PUBLIC_STATE_AUDIT_CONTRACT,
        'dispatchId'=>$dispatchId,
        'readOnly'=>true,
        'publicStateWrites'=>0,
        'state'=>[
            'stateRevision'=>$stateRevision,
            'updatedAt'=>$row['updated_at']??null,
            'profiles'=>$profileStats,
            'periods'=>$periods,
            'metricsRankingMeta'=>$metaSafe,
            'scoreStatuses'=>$scoreStatuses,
            'runReferenceCounts'=>$runReferences,
        ],
        'latestApplied'=>$latestApplied,
        'comparison'=>[
            'metadataMatchesLatestAppliedRun'=>$latestRun!==''&&$metaRun!==''&&hash_equals($latestRun,$metaRun),
            'profilesReferencingLatestAppliedRun'=>(int)($runReferences[$latestRun]??0),
            'revisionDeltaAfterLatestApply'=>$latestAfter>0?$stateRevision-$latestAfter:null,
            'rollbackRevisionStillMatches'=>$latestAfter>0&&$stateRevision===$latestAfter,
        ],
        'durationMs'=>(int)round((microtime(true)-$started)*1000),
    ]);
}catch(Throwable $error){
    error_log('PASS50 public ranking state audit: '.p50_mr_safe_error($error));
    json_response(['error'=>'Audit du classement public interrompu.'],500);
}
