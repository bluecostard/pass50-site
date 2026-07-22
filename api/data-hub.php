<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
$user = auth_user();
require_role($user,'owner','admin');
p50_de_ensure_schema();

if ($_SERVER['REQUEST_METHOD']==='GET') json_response(p50_de_hub_payload());
require_method('POST');
$in=json_input();
$action=(string)($in['action']??'sync');
if($action==='sync'){
    $count=p50_de_sync_registry_from_state();
    json_response(['ok'=>true,'syncedProfiles'=>$count,'hub'=>p50_de_hub_payload()]);
}
if($action==='publish'){
    $profileId=trim((string)($in['profileId']??''));
    $count=$profileId!==''?(p50_de_publish_profile($profileId,$user['id'])?1:0):p50_de_publish_all($user['id']);
    json_response(['ok'=>true,'publishedProfiles'=>$count,'hub'=>p50_de_hub_payload()]);
}
json_response(['error'=>'Action inconnue.'],422);
