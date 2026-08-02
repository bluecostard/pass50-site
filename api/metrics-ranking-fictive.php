<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-core.php';
require __DIR__.'/metrics-collector-readiness-core.php';

require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');

$pdo=db();
p50_mr_ensure_schema($pdo);
$period=strtoupper(trim((string)($_GET['period']??'24H')));
if(!array_key_exists($period,p50_mr_periods()))json_response(['error'=>'Période invalide.'],422);

$run=$pdo->query("SELECT run_uuid,algorithm_version,status,profiles_considered,classable_count,scores_written,started_at,finished_at FROM p50_metric_ranking_runs WHERE algorithm_version='MR-V1.0' AND status='success' ORDER BY finished_at DESC,id DESC LIMIT 1")->fetch();
if(!$run)json_response(['error'=>'Aucun classement expérimental disponible.'],404);

$runUuid=(string)$run['run_uuid'];
$periodSummary=null;
try{
    $ps=$pdo->prepare("SELECT profiles_considered,classable_count,excluded_count,average_confidence,average_coverage,threshold_excluded_count,hard_excluded_count,other_excluded_count,exclusion_summary_json,calculated_at FROM p50_metric_ranking_period_runs WHERE run_uuid=? AND period_key=? LIMIT 1");
    $ps->execute([$runUuid,$period]);
    $periodSummary=$ps->fetch()?:null;
}catch(Throwable){}

$profileColumns=['handle'=>"''",'region'=>"''",'category'=>"''"];
foreach(array_keys($profileColumns) as $column)if(p50_metrics_column_exists($pdo,'p50_profile_registry',$column))$profileColumns[$column]="r.`$column`";
$stmt=$pdo->prepare("SELECT c.rank_position,c.profile_id,r.public_name,{$profileColumns['handle']} handle,{$profileColumns['region']} region,{$profileColumns['category']} category,c.score,c.confidence,c.coverage,c.platform_count,c.content_count,c.capture_count,c.latest_capture_at,c.previous_rank,c.rank_delta,c.calculated_at FROM p50_metric_ranking_current c JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id WHERE c.algorithm_version=? AND c.period_key=? AND c.run_uuid=? AND c.classable=1 AND c.rank_position IS NOT NULL AND c.score IS NOT NULL ORDER BY c.rank_position LIMIT 50");
$stmt->execute([(string)$run['algorithm_version'],$period,$runUuid]);
$rows=[];$latestCaptureTs=null;$freshestCapture=null;$staleCount=0;
$freshnessHours=(int)(p50_mr_freshness_hours()[$period]??18);
$freshnessCutoff=time()-($freshnessHours*3600);
foreach($stmt->fetchAll() as $row){
  $captureAt=$row['latest_capture_at']?gmdate('c',strtotime((string)$row['latest_capture_at'])):null;
  $captureTs=$row['latest_capture_at']?strtotime((string)$row['latest_capture_at'].' UTC'):null;
  if($captureTs!==null){
    if($latestCaptureTs===null||$captureTs>$latestCaptureTs){$latestCaptureTs=$captureTs;$freshestCapture=$captureAt;}
    if($captureTs<$freshnessCutoff)$staleCount++;
  }
  $rows[]=[
    'rank'=>(int)$row['rank_position'],'profileId'=>(string)$row['profile_id'],'name'=>(string)$row['public_name'],
    'handle'=>(string)$row['handle'],'region'=>(string)$row['region'],'category'=>(string)$row['category'],
    'score'=>(float)$row['score'],'confidence'=>(float)$row['confidence'],'coverage'=>(float)$row['coverage'],
    'platformCount'=>(int)$row['platform_count'],'contentCount'=>(int)$row['content_count'],'captureCount'=>(int)$row['capture_count'],
    'latestCaptureAt'=>$captureAt,
    'captureFresh'=>$captureTs!==null&&$captureTs>=$freshnessCutoff,
    'previousRank'=>$row['previous_rank']===null?null:(int)$row['previous_rank'],'rankDelta'=>$row['rank_delta']===null?null:(int)$row['rank_delta'],
  ];
}

$exclusionSummary=[];
if($periodSummary&&!empty($periodSummary['exclusion_summary_json'])){
    $decoded=json_decode((string)$periodSummary['exclusion_summary_json'],true);
    if(is_array($decoded))$exclusionSummary=$decoded;
}
if(!$exclusionSummary){
    try{
        $reasonStmt=$pdo->prepare("SELECT exclusion_reasons_json FROM p50_metric_ranking_current WHERE algorithm_version=? AND period_key=? AND run_uuid=? AND classable=0");
        $reasonStmt->execute([(string)$run['algorithm_version'],$period,$runUuid]);
        foreach($reasonStmt->fetchAll(PDO::FETCH_COLUMN) as $reasonJson){
            foreach(json_decode((string)$reasonJson,true)?:[] as $reason){
                $key=(string)$reason;if($key==='')continue;
                $exclusionSummary[$key]=($exclusionSummary[$key]??0)+1;
            }
        }
        ksort($exclusionSummary);
    }catch(Throwable){}
}

$excludedSamples=[];
try{
    $ex=$pdo->prepare("SELECT c.profile_id,r.public_name,{$profileColumns['handle']} handle,c.confidence,c.coverage,c.exclusion_reasons_json FROM p50_metric_ranking_current c JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id WHERE c.algorithm_version=? AND c.period_key=? AND c.run_uuid=? AND c.classable=0 ORDER BY c.coverage DESC,c.confidence DESC LIMIT 12");
    $ex->execute([(string)$run['algorithm_version'],$period,$runUuid]);
    foreach($ex->fetchAll() as $row){
        $reasons=json_decode((string)$row['exclusion_reasons_json'],true)?:[];
        $excludedSamples[]=[
            'profileId'=>(string)$row['profile_id'],
            'name'=>(string)$row['public_name'],
            'handle'=>(string)$row['handle'],
            'confidence'=>(float)$row['confidence'],
            'coverage'=>(float)$row['coverage'],
            'reasons'=>array_values(array_map('strval',$reasons)),
        ];
    }
}catch(Throwable){}

$finishedAt=$run['finished_at']?gmdate('c',strtotime((string)$run['finished_at'])):null;
$runAgeHours=$run['finished_at']?max(0,round((time()-strtotime((string)$run['finished_at'].' UTC'))/3600,1)):null;

json_response([
  'ok'=>true,'version'=>'FICTIVE-RANKING-V1.1','mode'=>'internal_simulation','readOnly'=>true,
  'publicPublication'=>false,'publicStateWrites'=>0,'period'=>$period,'algorithmVersion'=>(string)$run['algorithm_version'],
  'run'=>[
    'runUuid'=>$runUuid,
    'status'=>(string)$run['status'],
    'profilesConsidered'=>(int)$run['profiles_considered'],
    'classableCount'=>(int)($periodSummary['classable_count']??$run['classable_count']),
    'excludedCount'=>(int)($periodSummary['excluded_count']??max(0,(int)$run['profiles_considered']-(int)$run['classable_count'])),
    'scoresWritten'=>(int)$run['scores_written'],
    'finishedAt'=>$finishedAt,
    'ageHours'=>$runAgeHours,
  ],
  'freshness'=>[
    'windowHours'=>$freshnessHours,
    'latestCaptureAt'=>$freshestCapture,
    'staleDisplayedCount'=>$staleCount,
    'runAgeHours'=>$runAgeHours,
  ],
  'exclusionSummary'=>$exclusionSummary,
  'excludedSamples'=>$excludedSamples,
  'periodStats'=>$periodSummary?[
    'averageConfidence'=>(float)$periodSummary['average_confidence'],
    'averageCoverage'=>(float)$periodSummary['average_coverage'],
    'thresholdExcludedCount'=>(int)$periodSummary['threshold_excluded_count'],
    'hardExcludedCount'=>(int)$periodSummary['hard_excluded_count'],
    'otherExcludedCount'=>(int)$periodSummary['other_excluded_count'],
    'calculatedAt'=>$periodSummary['calculated_at']?gmdate('c',strtotime((string)$periodSummary['calculated_at'])):null,
  ]:null,
  'summary'=>[
    'displayed'=>count($rows),
    'excluded'=>count($exclusionSummary)?array_sum(array_map('intval',$exclusionSummary)):(int)($periodSummary['excluded_count']??0),
    'warning'=>'Classement fictif interne. Ne pas confondre avec le classement public.',
  ],
  'collectorReadiness'=>p50_mcr_status($pdo),
  'rows'=>$rows,
]);
