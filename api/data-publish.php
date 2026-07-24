<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');
p50_de_ensure_schema();
p50_de_sync_registry_from_state();
$in=json_input();
$profileId=trim((string)($in['profileId']??''));

$period=(string)($in['period']??'2H');
if($profileId!=='')json_response(['error'=>'La publication atomique traite obligatoirement tout le classement.'],422);
$result=p50_de_publish_score_pipeline($user['id'],$period);

json_response(array_merge(
  ['ok'=>true],
  $result,
  ['threshold'=>p50_de_threshold(),'hub'=>p50_de_hub_payload()]
));
