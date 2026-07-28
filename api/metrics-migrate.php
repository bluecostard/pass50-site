<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-schema-core.php';

$user=auth_user();
require_role($user,'owner','admin');
require_method('GET','POST');

if($_SERVER['REQUEST_METHOD']==='GET'){
    json_response(['ok'=>true,'readOnly'=>true,'canonicalSchema'=>p50_metrics_schema_status(db())]);
}

$input=json_input();$action=(string)($input['action']??'migrate');
if($action!=='migrate')json_response(['error'=>'Action inconnue.'],422);
$limit=max(1,min(1000,(int)($input['limit']??500)));
try{
    $migration=p50_metrics_ensure_schema(db());
    $backfill=p50_metrics_backfill_legacy(db(),$limit);
    json_response(['ok'=>true,'migration'=>$migration,'backfill'=>$backfill,'canonicalSchema'=>p50_metrics_schema_status(db())]);
}catch(Throwable $error){
    error_log('PASS50 metrics migration: '.$error->getMessage());
    json_response(['error'=>'Migration métrique interrompue. Elle peut être relancée sans supprimer de données.'],500);
}
