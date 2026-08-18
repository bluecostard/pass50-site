<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');
set_time_limit(300);
ignore_user_abort(true);
p50_de_ensure_schema();
$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$includeHub=!empty($in['includeHub']);
$period=(string)($in['period']??'2H');
if($profileId!=='')json_response(['error'=>'La publication atomique traite obligatoirement tout le classement.'],422);
try{
    $result=p50_de_publish_score_pipeline($user['id'],$period);
}catch(Throwable $e){
    error_log('PASS50 data-publish: '.$e->getMessage());
    json_response(['error'=>'Publication du classement interrompue. Relance la MAJ pour terminer l’écriture.'],500);
}
json_response(array_merge(
  ['ok'=>true],
  $result,
  ['threshold'=>p50_de_threshold(),'hub'=>$includeHub?p50_de_hub_payload():null]
));
