<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-ranking-publication-history-core.php';

require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');

try{
    $period=p50_mrp_period((string)($_GET['period']??'2H'));
    $sample=max(P50_MRPH_MIN_DISTINCT_CYCLES,min(24,(int)($_GET['sample']??P50_MRPH_MIN_DISTINCT_CYCLES)));
    $limit=max($sample,min(100,(int)($_GET['limit']??24)));
    $pdo=db();
    json_response([
        'ok'=>true,
        'publicationMode'=>'simulation',
        'publicStateWrites'=>0,
        'stability'=>p50_mrph_stability($pdo,$period,$sample),
        'history'=>p50_mrph_recent($pdo,$period,$limit),
    ]);
}catch(Throwable $error){
    error_log('PASS50 publication simulation history: '.p50_mr_safe_error($error));
    json_response(['error'=>'Historique de simulation indisponible.'],500);
}
