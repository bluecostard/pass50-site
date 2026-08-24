<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-core.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';

require_method('GET','POST');
$user=auth_user();
require_role($user,'owner','admin');
$pdo=db();

if($_SERVER['REQUEST_METHOD']==='GET'){
    json_response(p50_mr_read($pdo,(string)($_GET['period']??'2H'),(int)($_GET['limit']??100)));
}

$input=json_input();
if(($input['action']??'')!=='calculate')json_response(['error'=>'Action invalide.'],400);
$periods=is_array($input['periods']??null)?$input['periods']:array_keys(p50_mr_periods());
try{
    p50_mr_ensure_schema($pdo);
    $result=p50_mr_calculate($pdo,$periods,'admin_manual');
    p50_mrp_apply_clear_preview_cache($pdo);
    json_response($result);
}catch(Throwable $error){
    error_log('Metrics ranking experimental: '.$error->getMessage());
    json_response(['error'=>'Calcul expérimental impossible.'],500);
}
