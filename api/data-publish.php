<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require __DIR__ . '/metrics-schema-core.php';

require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');
set_time_limit(300);
ignore_user_abort(true);

register_shutdown_function(static function(): void {
    $err=error_get_last();
    if(!$err||!in_array((int)$err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
    if(headers_sent())return;
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'=>false,
        'error'=>'Publication du classement interrompue (fatal serveur).',
        'detail'=>mb_substr((string)($err['message']??''),0,300),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});

try{
    p50_de_ensure_schema();
    $in=json_input();
    $profileId=trim((string)($in['profileId']??''));
    $includeHub=!empty($in['includeHub']);
    $period=(string)($in['period']??'2H');
    if($profileId!=='')json_response(['error'=>'La publication atomique traite obligatoirement tout le classement.'],422);
    $result=p50_de_publish_score_pipeline($user['id'],$period);
    json_response(array_merge(
        ['ok'=>true],
        $result,
        ['threshold'=>p50_de_threshold(),'hub'=>$includeHub?p50_de_hub_payload():null]
    ));
}catch(Throwable $error){
    error_log('PASS50 data-publish: '.p50_metrics_safe_error($error->getMessage()));
    json_response([
        'ok'=>false,
        'error'=>'Publication du classement interrompue. Relance la MAJ pour terminer l’écriture.',
        'detail'=>p50_metrics_safe_error($error->getMessage()),
    ],500);
}
