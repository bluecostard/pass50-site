<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-apply-core.php';

$user=auth_user();
require_role($user,'owner','admin');

try{
    $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $getAction=strtolower(trim((string)($_GET['action']??'')));
        // Santé légère : ne dépend pas de la preview (lourde / parfois 500).
        if($getAction==='health'){
            p50_mrp_apply_ensure_schema($pdo);
            json_response(['ok'=>true,'health'=>p50_mrp_apply_health($pdo)]);
        }
        $preview=p50_mrp_apply_preview($pdo);
        $preview['forcedBootstrapEnabled']=false;
        json_response($preview);
    }
    require_method('POST');
    $in=json_input();
    if(array_key_exists('bootstrap',$in))json_response([
        'error'=>'Le bootstrap de récupération a déjà été consommé.',
        'reason'=>'bootstrap_recovery_consumed',
        'publicStateWrites'=>0,
    ],409);
    $action=trim((string)($in['action']??'preview'));
    if($action==='health'){
        p50_mrp_apply_ensure_schema($pdo);
        json_response(['ok'=>true,'health'=>p50_mrp_apply_health($pdo)]);
    }
    if($action==='preview'){
        $preview=p50_mrp_apply_preview($pdo);
        $preview['forcedBootstrapEnabled']=false;
        json_response($preview);
    }
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
        'bootstrap'=>false,
    ]);
    json_response($result);
}catch(InvalidArgumentException $error){
    json_response(['error'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('PASS50 publication apply: '.p50_mr_safe_error($error));
    json_response(['error'=>$error->getMessage()!==''?$error->getMessage():'Publication impossible.'],500);
}
