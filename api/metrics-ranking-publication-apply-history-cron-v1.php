<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';

const P50_MRP_APPLY_HISTORY_CONTRACT='PUBAPPLY-HISTORY-V1.0';

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
if(!is_string($action)||!in_array($action,['probe','history'],true))json_response(['error'=>'Action invalide.'],422);
if(!is_string($input['dispatchId']??null))json_response(['error'=>'dispatchId invalide.'],422);
$dispatchId=trim($input['dispatchId']);
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$started=microtime(true);
try{
    $pdo=db();
    $tableExists=p50_metrics_table_exists($pdo,'p50_metric_publication_applies');
    if($action==='probe')json_response([
        'ok'=>true,
        'action'=>'probe',
        'contract'=>P50_MRP_APPLY_HISTORY_CONTRACT,
        'dispatchId'=>$dispatchId,
        'tableExists'=>$tableExists,
        'readOnly'=>true,
        'publicStateWrites'=>0,
    ]);

    $rows=[];
    if($tableExists){
        $stmt=$pdo->query("SELECT id,apply_uuid,dispatch_id,mode,status,algorithm_version,run_uuid,periods_json,
            public_revision_before,public_revision_after,profiles_updated,scores_written,entries_count,exits_count,
            bootstrap,applied_by,error_message,generated_at,created_at
          FROM p50_metric_publication_applies ORDER BY id DESC LIMIT 25");
        foreach($stmt->fetchAll() as $row){
            $periods=json_decode((string)$row['periods_json'],true);
            $periods=is_array($periods)?array_values(array_filter(array_map('strval',$periods))):[];
            $appliedBy=trim((string)($row['applied_by']??''));
            $actor=$appliedBy===''?'system':(str_starts_with($appliedBy,'cron-')?$appliedBy:(preg_match('/^[0-9a-fA-F-]{36}$/',$appliedBy)?'authenticated_user':'system'));
            $rows[]=[
                'id'=>(int)$row['id'],
                'applyUuid'=>(string)$row['apply_uuid'],
                'dispatchId'=>(string)$row['dispatch_id'],
                'mode'=>(string)$row['mode'],
                'status'=>(string)$row['status'],
                'algorithmVersion'=>(string)$row['algorithm_version'],
                'runUuid'=>(string)$row['run_uuid'],
                'periods'=>$periods,
                'publicRevisionBefore'=>(int)$row['public_revision_before'],
                'publicRevisionAfter'=>(int)$row['public_revision_after'],
                'profilesUpdated'=>(int)$row['profiles_updated'],
                'scoresWritten'=>(int)$row['scores_written'],
                'entries'=>(int)$row['entries_count'],
                'exits'=>(int)$row['exits_count'],
                'bootstrap'=>(bool)$row['bootstrap'],
                'actor'=>$actor,
                'error'=>trim((string)($row['error_message']??''))?:null,
                'generatedAt'=>(string)$row['generated_at'],
                'createdAt'=>(string)$row['created_at'],
            ];
        }
    }
    $statusCounts=[];
    foreach($rows as $row)$statusCounts[$row['status']]=($statusCounts[$row['status']]??0)+1;
    ksort($statusCounts);
    $successful=array_values(array_filter($rows,static fn(array $row): bool=>$row['status']==='applied'));

    json_response([
        'ok'=>true,
        'action'=>'history',
        'contract'=>P50_MRP_APPLY_HISTORY_CONTRACT,
        'dispatchId'=>$dispatchId,
        'readOnly'=>true,
        'publicStateWrites'=>0,
        'tableExists'=>$tableExists,
        'rowCount'=>count($rows),
        'statusCounts'=>$statusCounts,
        'hasPriorSuccess'=>$successful!==[],
        'latestApplied'=>$successful[0]??null,
        'rows'=>$rows,
        'durationMs'=>(int)round((microtime(true)-$started)*1000),
    ]);
}catch(Throwable $error){
    error_log('PASS50 publication apply history: '.p50_mr_safe_error($error));
    json_response(['error'=>'Audit des publications interrompu.'],500);
}
