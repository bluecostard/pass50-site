<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-core.php';

require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');

try{
    json_response(p50_mrp_simulate(db(),(string)($_GET['period']??'2H'),(int)($_GET['limit']??200)));
}catch(Throwable $error){
    error_log('PASS50 publication simulation: '.p50_mr_safe_error($error));
    json_response(['error'=>'Simulation de publication indisponible.'],500);
}
