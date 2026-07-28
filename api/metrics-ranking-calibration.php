<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-calibration-core.php';

require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');

try{
    json_response(p50_mrc_read(
        db(),
        (string)($_GET['period']??'2H'),
        (int)($_GET['runs']??24)
    ));
}catch(Throwable $error){
    error_log('Metrics ranking calibration: '.p50_mr_safe_error($error));
    json_response(['error'=>'Historique expérimental indisponible.'],500);
}
