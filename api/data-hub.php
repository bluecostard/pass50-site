<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require __DIR__ . '/metrics-schema-core.php';

set_time_limit(180);
ignore_user_abort(true);

register_shutdown_function(static function(): void {
    $err=error_get_last();
    if(!$err||!in_array((int)$err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
    if(headers_sent())return;
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'=>false,
        'error'=>'Hub Data Engine interrompu (fatal serveur).',
        'detail'=>mb_substr((string)($err['message']??''),0,300),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});

$user=auth_user();
require_role($user,'owner','admin');

try{
    p50_de_ensure_schema();
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $forceSync=isset($_GET['sync'])&&(string)$_GET['sync']==='1';
        json_response(p50_de_hub_payload($forceSync));
    }
    require_method('POST');
    $in=json_input();
    $action=(string)($in['action']??'sync');
    if($action==='sync'){
        $count=p50_de_sync_registry_from_state();
        json_response(['ok'=>true,'syncedProfiles'=>$count,'hub'=>p50_de_hub_payload()]);
    }
    if($action==='publish'){
        $profileId=trim((string)($in['profileId']??''));
        $count=$profileId!==''?(p50_de_publish_profile($profileId,$user['id'])?1:0):p50_de_publish_all($user['id']);
        json_response(['ok'=>true,'publishedProfiles'=>$count,'hub'=>p50_de_hub_payload()]);
    }
    json_response(['error'=>'Action inconnue.'],422);
}catch(Throwable $error){
    error_log('PASS50 data-hub: '.p50_metrics_safe_error($error->getMessage()));
    json_response([
        'ok'=>false,
        'error'=>'Hub Data Engine indisponible.',
        'detail'=>p50_metrics_safe_error($error->getMessage()),
    ],500);
}
