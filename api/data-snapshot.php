<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');
p50_de_ensure_schema();
$in=json_input();
$period=(string)($in['period']??'2H');
if(!in_array($period,['2H','24H','48H','7J','15J'],true))json_response(['error'=>'Période invalide.'],422);
$count=p50_de_capture_snapshots($period);
json_response(['ok'=>true,'captured'=>$count,'period'=>$period,'capturedAt'=>gmdate('c')]);
