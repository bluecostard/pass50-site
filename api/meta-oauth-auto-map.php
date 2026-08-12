<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';
require __DIR__.'/meta-oauth-assets.php';
require_method('POST');
p50mo_ensure_schema();

$configuredSecret=trim((string)($config['metrics']['cron_secret']??''));
$providedSecret=trim((string)($_SERVER['HTTP_X_PASS50_CRON_SECRET']??''));
$cron=$configuredSecret!==''&&strlen($configuredSecret)>=32&&$providedSecret!==''&&hash_equals($configuredSecret,$providedSecret);

if($cron){
    $result=p50mo_auto_map_unmapped_assets(null);
    json_response([
        'ok'=>true,
        'mode'=>'cron',
        'checked'=>(int)$result['checked'],
        'mapped'=>(int)$result['mapped'],
        'results'=>[],
        'mappedAt'=>gmdate(DATE_ATOM),
    ]);
}

$user=auth_user();
$userId=(string)$user['id'];
$connection=p50mo_connection($userId);
if(!$connection)json_response(['error'=>'Aucun compte Meta connecté.'],409);

$result=p50mo_auto_map_unmapped_assets($userId);
json_response([
    'ok'=>true,
    'mode'=>'user',
    'checked'=>(int)$result['checked'],
    'mapped'=>(int)$result['mapped'],
    'results'=>$result['results'],
    'mappedAt'=>gmdate(DATE_ATOM),
]);
