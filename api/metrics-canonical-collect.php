<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-collectors-core.php';

$user=auth_user();require_role($user,'owner','admin');require_method('GET','POST');
set_time_limit(180);
$pdo=db();
if($_SERVER['REQUEST_METHOD']==='GET')json_response(['ok'=>true,'collectors'=>p50_metrics_collectors_status($pdo),'limits'=>['profiles'=>10,'profileContents'=>10,'batchContents'=>5]]);
$input=json_input();$action=(string)($input['action']??'status');
if($action==='status')json_response(['ok'=>true,'collectors'=>p50_metrics_collectors_status($pdo)]);
$platform=p50_mc_platform((string)($input['platform']??''));if($platform==='')json_response(['error'=>'Plateforme autorisée : YouTube ou X.'],422);
$profileId=trim((string)($input['profileId']??''));$lock='pass50_metrics_collect_'.strtolower($platform).'_'.($action==='collect_profile'?hash('sha256',$profileId):'batch');
if((int)p50_metrics_value($pdo,"SELECT GET_LOCK(?,2)",[$lock])!==1)json_response(['error'=>'Une collecte identique est déjà en cours.'],409);
try{
    if($action==='collect_profile'){
        if($profileId==='')json_response(['error'=>'profileId obligatoire.'],422);
        $result=p50_metrics_collect_profile($pdo,$profileId,$platform,min(10,max(1,(int)($input['contentLimit']??5))));
        json_response(['ok'=>true,'mode'=>'collect_profile','summary'=>p50_mc_summary([$result])['summary'],'details'=>[$result],'experimental'=>true]);
    }
    if($action==='collect_batch'){
        $result=p50_metrics_collect_batch($pdo,$platform,min(10,max(1,(int)($input['profileLimit']??10))),min(5,max(1,(int)($input['contentLimit']??5))));
        json_response(['ok'=>true,'mode'=>'collect_batch']+$result+['experimental'=>true]);
    }
    json_response(['error'=>'Action inconnue.'],422);
}catch(Throwable $error){
    error_log('PASS50 canonical metrics collection: '.p50_metrics_safe_error($error->getMessage()));
    json_response(['error'=>'Collecte métrique interrompue.','detail'=>p50_metrics_safe_error($error->getMessage())],500);
}finally{try{$stmt=$pdo->prepare("SELECT RELEASE_LOCK(?)");$stmt->execute([$lock]);}catch(Throwable){}}
