<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-history-core.php';

const P50_MR_STABILITY_AUDIT_CONTRACT='MR-STABILITY-AUDIT-V1.0';
const P50_MR_STABILITY_AUDIT_SAMPLE_SIZE=6;
const P50_MR_STABILITY_AUDIT_WARN_VOLATILITY=20.0;
const P50_MR_STABILITY_AUDIT_BLOCK_VOLATILITY=35.0;
const P50_MR_STABILITY_AUDIT_MAX_AGE_HOURS=6.0;

function p50_mrsa_median(array $values): ?float {
    $numbers=array_values(array_map('floatval',array_filter($values,'is_numeric')));
    if(!$numbers)return null;
    sort($numbers,SORT_NUMERIC);
    $count=count($numbers);$middle=intdiv($count,2);
    return $count%2===1?$numbers[$middle]:($numbers[$middle-1]+$numbers[$middle])/2;
}

function p50_mrsa_round(?float $value,int $precision=3): ?float {
    return $value===null?null:round($value,$precision);
}

function p50_mrsa_distinct_recent(array $rows,int $sampleSize): array {
    $selected=[];$seen=[];
    foreach($rows as $row){
        if(!is_array($row))continue;
        $run=trim((string)($row['experimental_run_uuid']??''));
        $key=$run!==''?'run:'.$run:'row:'.(string)($row['id']??count($selected));
        if(isset($seen[$key]))continue;
        $seen[$key]=true;$selected[]=$row;
        if(count($selected)>=$sampleSize)break;
    }
    return $selected;
}

function p50_mrsa_period(PDO $pdo,string $period,DateTimeImmutable $now): array {
    $limit=max(30,P50_MR_STABILITY_AUDIT_SAMPLE_SIZE*10);
    $stmt=$pdo->prepare("SELECT id,status,experimental_run_uuid,public_state_revision,
        public_fingerprint,candidate_fingerprint,public_count,candidate_count,
        entries_count,exits_count,blocked_gate_count,warning_gate_count,public_state_writes,
        median_rank_movement,maximum_rank_movement,top10_retention,generated_at
      FROM p50_metric_publication_simulations
      WHERE period_key=? ORDER BY generated_at DESC,id DESC LIMIT $limit");
    $stmt->execute([$period]);
    $all=$stmt->fetchAll();
    $rows=p50_mrsa_distinct_recent($all,P50_MR_STABILITY_AUDIT_SAMPLE_SIZE);

    $candidateCounts=[];$publicCounts=[];$entries=[];$exits=[];$revisions=[];
    $publicFingerprints=[];$candidateFingerprints=[];$runs=[];
    $blocked=0;$review=0;$ready=0;$writeAnomalies=0;
    $cycles=[];
    foreach($rows as $index=>$row){
        $status=(string)($row['status']??'blocked');
        if($status==='blocked')$blocked++;
        elseif($status==='review')$review++;
        elseif($status==='ready')$ready++;
        $writes=(int)($row['public_state_writes']??0);
        if($writes!==0)$writeAnomalies++;
        $candidate=(int)($row['candidate_count']??0);$public=(int)($row['public_count']??0);
        $entryCount=(int)($row['entries_count']??0);$exitCount=(int)($row['exits_count']??0);
        $candidateCounts[]=$candidate;$publicCounts[]=$public;$entries[]=$entryCount;$exits[]=$exitCount;
        $revisions[(string)(int)($row['public_state_revision']??0)]=true;
        $publicFp=(string)($row['public_fingerprint']??'');if($publicFp!=='')$publicFingerprints[$publicFp]=true;
        $candidateFp=(string)($row['candidate_fingerprint']??'');if($candidateFp!=='')$candidateFingerprints[$candidateFp]=true;
        $run=(string)($row['experimental_run_uuid']??'');if($run!=='')$runs[$run]=true;
        $cycles[]=[
            'sequence'=>$index+1,
            'status'=>$status,
            'publicRevision'=>(int)($row['public_state_revision']??0),
            'publicCount'=>$public,
            'candidateCount'=>$candidate,
            'entries'=>$entryCount,
            'exits'=>$exitCount,
            'blockedGateCount'=>(int)($row['blocked_gate_count']??0),
            'warningGateCount'=>(int)($row['warning_gate_count']??0),
            'publicStateWrites'=>$writes,
            'medianRankMovement'=>$row['median_rank_movement']===null?null:(float)$row['median_rank_movement'],
            'maximumRankMovement'=>$row['maximum_rank_movement']===null?null:(int)$row['maximum_rank_movement'],
            'top10Retention'=>$row['top10_retention']===null?null:(float)$row['top10_retention'],
            'generatedAt'=>(string)($row['generated_at']??''),
        ];
    }

    $latest=$rows[0]??null;$latestAgeHours=null;
    if(is_array($latest)&&trim((string)($latest['generated_at']??''))!==''){
        $latestAt=new DateTimeImmutable((string)$latest['generated_at'],new DateTimeZone('UTC'));
        $latestAgeHours=max(0,($now->getTimestamp()-$latestAt->getTimestamp())/3600);
    }
    $candidateMedian=p50_mrsa_median($candidateCounts);
    $candidateMin=$candidateCounts?min($candidateCounts):null;
    $candidateMax=$candidateCounts?max($candidateCounts):null;
    $volatility=$candidateMedian!==null&&$candidateMedian>0&&$candidateMin!==null&&$candidateMax!==null
        ?(($candidateMax-$candidateMin)/$candidateMedian)*100:null;
    $latestDelta=count($candidateCounts)>=2?$candidateCounts[0]-$candidateCounts[1]:null;
    $enough=count($rows)>=P50_MRPH_MIN_DISTINCT_CYCLES;
    $fresh=$latestAgeHours!==null&&$latestAgeHours<=P50_MR_STABILITY_AUDIT_MAX_AGE_HOURS;
    $publicStable=count($revisions)<=1&&count($publicFingerprints)<=1;

    if(!$enough)$state='collecting';
    elseif($writeAnomalies>0)$state='blocked';
    elseif(!$fresh)$state='stale';
    elseif($blocked>0)$state='blocked';
    elseif(!$publicStable)$state='baseline_changed';
    elseif($volatility!==null&&$volatility>P50_MR_STABILITY_AUDIT_BLOCK_VOLATILITY)$state='unstable';
    elseif($review>0||($volatility!==null&&$volatility>P50_MR_STABILITY_AUDIT_WARN_VOLATILITY))$state='review';
    else $state='stable';

    return [
        'period'=>$period,
        'state'=>$state,
        'sampleSize'=>count($rows),
        'requiredSampleSize'=>P50_MRPH_MIN_DISTINCT_CYCLES,
        'distinctRunCount'=>count($runs),
        'latestAgeHours'=>p50_mrsa_round($latestAgeHours),
        'latestStatus'=>(string)($latest['status']??'none'),
        'latestPublicRevision'=>(int)($latest['public_state_revision']??0),
        'latestPublicCount'=>(int)($latest['public_count']??0),
        'latestCandidateCount'=>(int)($latest['candidate_count']??0),
        'latestCandidateDelta'=>$latestDelta,
        'statusCounts'=>['ready'=>$ready,'review'=>$review,'blocked'=>$blocked],
        'publicBaseline'=>[
            'stable'=>$publicStable,
            'distinctRevisionCount'=>count($revisions),
            'distinctFingerprintCount'=>count($publicFingerprints),
        ],
        'candidateSeries'=>[
            'minimum'=>$candidateMin,
            'maximum'=>$candidateMax,
            'median'=>p50_mrsa_round($candidateMedian),
            'volatilityPercent'=>p50_mrsa_round($volatility),
            'distinctFingerprintCount'=>count($candidateFingerprints),
        ],
        'entrySeries'=>[
            'minimum'=>$entries?min($entries):null,
            'maximum'=>$entries?max($entries):null,
            'median'=>p50_mrsa_round(p50_mrsa_median($entries)),
        ],
        'exitSeries'=>[
            'minimum'=>$exits?min($exits):null,
            'maximum'=>$exits?max($exits):null,
            'median'=>p50_mrsa_round(p50_mrsa_median($exits)),
        ],
        'writeAnomalies'=>$writeAnomalies,
        'cycles'=>$cycles,
    ];
}

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$contentType=strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
if(!preg_match('~^application/json(?:\s*;\s*charset=[A-Za-z0-9._-]+)?$~',$contentType))json_response(['error'=>'Type de contenu refusé.'],415);
$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>16384)json_response(['error'=>'Corps trop volumineux.'],413);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>16384)json_response(['error'=>'Corps invalide.'],413);

$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled'])json_response(['error'=>'Orchestrateur métrique désactivé.'],503);
if(strlen($secret)<32)json_response(['error'=>'Cron métrique non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$keys=array_keys($input);sort($keys);if($keys!==['action','dispatchId'])json_response(['error'=>'Corps JSON invalide.'],422);
$action=$input['action']??null;if(!is_string($action)||!in_array($action,['probe','audit'],true))json_response(['error'=>'Action invalide.'],422);
if(!is_string($input['dispatchId']??null))json_response(['error'=>'dispatchId invalide.'],422);
$dispatchId=trim($input['dispatchId']);if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$started=microtime(true);
try{
    $pdo=db();
    $tableExists=p50_metrics_table_exists($pdo,'p50_metric_publication_simulations');
    if($action==='probe')json_response([
        'ok'=>true,'action'=>'probe','contract'=>P50_MR_STABILITY_AUDIT_CONTRACT,
        'dispatchId'=>$dispatchId,'tableExists'=>$tableExists,
        'readOnly'=>true,'advisoryOnly'=>true,'authorizesPublication'=>false,'publicStateWrites'=>0,
    ]);
    if(!$tableExists)json_response(['error'=>'Historique de simulation absent.'],404);
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $periods=[];$states=[];
    foreach(array_keys(p50_mr_periods()) as $period){
        $periods[$period]=p50_mrsa_period($pdo,$period,$now);
        $states[$period]=$periods[$period]['state'];
    }
    $blockingStates=['blocked','stale','baseline_changed','unstable'];
    $reviewStates=['review','collecting'];
    $blocked=(bool)array_filter($states,static fn(string $state): bool=>in_array($state,$blockingStates,true));
    $review=(bool)array_filter($states,static fn(string $state): bool=>in_array($state,$reviewStates,true));
    $overall=$blocked?'blocked':($review?'review':'stable');
    json_response([
        'ok'=>true,'action'=>'audit','contract'=>P50_MR_STABILITY_AUDIT_CONTRACT,
        'dispatchId'=>$dispatchId,'generatedAt'=>$now->format(DATE_ATOM),
        'readOnly'=>true,'advisoryOnly'=>true,'authorizesPublication'=>false,'publicStateWrites'=>0,
        'thresholds'=>[
            'minimumDistinctCycles'=>P50_MRPH_MIN_DISTINCT_CYCLES,
            'maximumLatestAgeHours'=>P50_MR_STABILITY_AUDIT_MAX_AGE_HOURS,
            'candidateVolatilityWarnPercent'=>P50_MR_STABILITY_AUDIT_WARN_VOLATILITY,
            'candidateVolatilityBlockPercent'=>P50_MR_STABILITY_AUDIT_BLOCK_VOLATILITY,
        ],
        'overallState'=>$overall,'periodStates'=>$states,'periods'=>$periods,
        'durationMs'=>(int)round((microtime(true)-$started)*1000),
    ]);
}catch(Throwable $error){
    error_log('PASS50 simulation stability audit: '.p50_mr_safe_error($error));
    json_response(['error'=>'Audit de stabilité interrompu.'],500);
}
