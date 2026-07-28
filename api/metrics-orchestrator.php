<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';

$user=auth_user();require_role($user,'owner','admin');require_method('GET','POST');$pdo=db();
if($_SERVER['REQUEST_METHOD']==='GET'){
    $recentJobs=$pdo->query("SELECT job_uuid,collector,platform,scope_id,priority,status,scheduled_at,next_attempt_at,attempts,max_attempts,locked_at,last_error,created_at,updated_at FROM p50_metric_jobs ORDER BY id DESC LIMIT 50")->fetchAll();
    $recentRuns=$pdo->query("SELECT run_uuid,job_uuid,collector,platform,trigger_type,status,started_at,finished_at,error_message FROM p50_metric_runs WHERE collector='metrics_orchestrator_v1' OR job_uuid IS NOT NULL ORDER BY id DESC LIMIT 50")->fetchAll();
    json_response(['ok'=>true,'orchestrator'=>p50_mo_status($pdo),'recentJobs'=>$recentJobs,'recentRuns'=>$recentRuns]);
}
$input=json_input();foreach(['token','secret','url','endpoint','headers','sql','query'] as $forbidden)if(array_key_exists($forbidden,$input))json_response(['error'=>'Paramètre interdit.'],422);
$action=(string)($input['action']??'');$cadence=(string)($input['cadence']??'p0');$dispatchId=substr(trim((string)($input['dispatchId']??('admin-'.gmdate('YmdHis')))),0,120);
try{
    $result=match($action){
      'preview'=>p50_mo_dispatch($pdo,$cadence,$dispatchId,['preview'=>true,'source'=>'admin']),
      'enqueue'=>p50_mo_dispatch($pdo,$cadence,$dispatchId,['source'=>'admin']),
      'work_one'=>p50_metrics_process_next_job($pdo),
      'recover_stale'=>['ok'=>true,'recovery'=>p50_metrics_recover_stale_jobs($pdo)],
      default=>throw new InvalidArgumentException('Action inconnue.'),
    };json_response($result);
}catch(InvalidArgumentException $error){json_response(['error'=>p50_metrics_safe_error($error->getMessage())],422);}
catch(Throwable $error){error_log('PASS50 metrics orchestrator: '.p50_metrics_safe_error($error->getMessage()));json_response(['error'=>'Action orchestrateur interrompue.'],500);}
