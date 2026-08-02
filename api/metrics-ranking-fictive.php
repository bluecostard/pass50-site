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

$profileColumns=['handle'=>"''",'region'=>"''",'category'=>"''"];
foreach(array_keys($profileColumns) as $column)if(p50_metrics_column_exists($pdo,'p50_profile_registry',$column))$profileColumns[$column]="r.`$column`";
$stmt=$pdo->prepare("SELECT c.rank_position,c.profile_id,r.public_name,{$profileColumns['handle']} handle,{$profileColumns['region']} region,{$profileColumns['category']} category,c.score,c.confidence,c.coverage,c.platform_count,c.content_count,c.capture_count,c.latest_capture_at,c.previous_rank,c.rank_delta,c.calculated_at FROM p50_metric_ranking_current c JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id WHERE c.algorithm_version=? AND c.period_key=? AND c.run_uuid=? AND c.classable=1 AND c.rank_position IS NOT NULL AND c.score IS NOT NULL ORDER BY c.rank_position LIMIT 50");
$stmt->execute([(string)$run['algorithm_version'],$period,(string)$run['run_uuid']]);
$rows=[];
foreach($stmt->fetchAll() as $row)$rows[]=[
  'rank'=>(int)$row['rank_position'],'profileId'=>(string)$row['profile_id'],'name'=>(string)$row['public_name'],
  'handle'=>(string)$row['handle'],'region'=>(string)$row['region'],'category'=>(string)$row['category'],
  'score'=>(float)$row['score'],'confidence'=>(float)$row['confidence'],'coverage'=>(float)$row['coverage'],
  'platformCount'=>(int)$row['platform_count'],'contentCount'=>(int)$row['content_count'],'captureCount'=>(int)$row['capture_count'],
  'latestCaptureAt'=>$row['latest_capture_at']?gmdate('c',strtotime((string)$row['latest_capture_at'])):null,
  'previousRank'=>$row['previous_rank']===null?null:(int)$row['previous_rank'],'rankDelta'=>$row['rank_delta']===null?null:(int)$row['rank_delta'],
];

json_response([
  'ok'=>true,'version'=>'FICTIVE-RANKING-V1.0','mode'=>'internal_simulation','readOnly'=>true,
  'publicPublication'=>false,'publicStateWrites'=>0,'period'=>$period,'algorithmVersion'=>(string)$run['algorithm_version'],
  'run'=>['runUuid'=>(string)$run['run_uuid'],'status'=>(string)$run['status'],'profilesConsidered'=>(int)$run['profiles_considered'],'classableCount'=>(int)$run['classable_count'],'scoresWritten'=>(int)$run['scores_written'],'finishedAt'=>$run['finished_at']?gmdate('c',strtotime((string)$run['finished_at'])):null],
  'summary'=>['displayed'=>count($rows),'warning'=>'Classement fictif interne. Ne pas confondre avec le classement public.'],
  'collectorReadiness'=>p50_mcr_status($pdo),
  'rows'=>$rows,
]);
