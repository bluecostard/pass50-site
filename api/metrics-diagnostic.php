<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/metrics-schema-core.php';
require __DIR__.'/metrics-collectors-core.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/metrics-observability-core.php';
require __DIR__.'/metrics-control-center-core.php';
require __DIR__.'/metrics-ranking-readiness-core.php';

set_time_limit(120);
ignore_user_abort(true);

register_shutdown_function(static function(): void {
    $err=error_get_last();
    if(!$err||!in_array((int)$err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
    if(headers_sent())return;
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'=>false,
        'error'=>'Diagnostic interrompu (fatal serveur).',
        'detail'=>mb_substr((string)($err['message']??''),0,300),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});

require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');

try{
    $pdo=db();
    $threshold=p50_de_threshold();
    $diagnostic=p50_obs_diagnostic($pdo,$threshold);
    try{
        $diagnostic['controlCenter']=p50mcc_status($pdo,$threshold);
    }catch(Throwable $error){
        error_log('PASS50 metrics-diagnostic controlCenter: '.p50_metrics_safe_error($error));
        $diagnostic['controlCenter']=[
            'version'=>P50_METRICS_CONTROL_CENTER_VERSION,
            'partial'=>true,
            'error'=>'Centre de contrôle indisponible.',
            'detail'=>p50_metrics_safe_error($error),
        ];
    }
    try{
        $diagnostic['rankingReadiness']=p50_mrr_readiness($pdo,new DateTimeImmutable('now',new DateTimeZone('UTC')));
    }catch(Throwable $error){
        error_log('PASS50 metrics-diagnostic rankingReadiness: '.p50_metrics_safe_error($error));
        $diagnostic['rankingReadiness']=[
            'version'=>P50_MR_READINESS_VERSION,
            'ready'=>false,
            'state'=>'blocked',
            'reason'=>'readiness_error',
            'detail'=>p50_metrics_safe_error($error),
        ];
    }
    json_response($diagnostic);
}catch(Throwable $error){
    error_log('PASS50 metrics-diagnostic: '.p50_metrics_safe_error($error));
    json_response([
        'ok'=>false,
        'error'=>'Diagnostic métrique interrompu.',
        'detail'=>p50_metrics_safe_error($error),
    ],500);
}
