<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';

$user=auth_user();
require_role($user,'owner','admin');

try{
    $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='GET'){
        json_response(p50_mrp_apply_preview($pdo));
    }
    require_method('POST');
    $in=json_input();
    $action=trim((string)($in['action']??'preview'));
    if($action==='preview')json_response(p50_mrp_apply_preview($pdo));
    if($action==='rollback'){
        $applyUuid=trim((string)($in['applyUuid']??''));
        if($applyUuid==='')json_response(['error'=>'applyUuid requis.'],422);
        json_response(p50_mrp_apply_rollback($pdo,$applyUuid,(string)($user['id']??'admin')));
    }
    if($action!=='apply')json_response(['error'=>'Action invalide.'],422);
    $result=p50_mrp_apply_execute($pdo,[
        'mode'=>'controlled',
        'dispatchId'=>trim((string)($in['dispatchId']??('admin-'.bin2hex(random_bytes(8))))),
        'appliedBy'=>(string)($user['id']??'admin'),
        'confirm'=>!empty($in['confirm']),
        'bootstrap'=>!empty($in['bootstrap']),
    ]);
    json_response($result);
}catch(InvalidArgumentException $error){
    json_response(['error'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('PASS50 publication apply: '.p50_mr_safe_error($error));
    json_response(['error'=>$error->getMessage()!==''?$error->getMessage():'Publication impossible.'],500);
}
