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
$count=$profileId!==''?(p50_de_publish_profile($profileId,$user['id'])?1:0):p50_de_publish_all($user['id']);
json_response(['ok'=>true,'publishedProfiles'=>$count,'threshold'=>p50_de_threshold(),'hub'=>p50_de_hub_payload()]);
