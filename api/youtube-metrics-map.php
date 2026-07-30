<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/youtube-metrics-bridge-core.php';
require_method('POST');

$user=auth_user();
require_role($user,'owner','admin');
p50_de_sync_registry_from_state();

$input=json_input();
$channelId=trim((string)($input['channelId']??''));
$profileId=trim((string)($input['profileId']??''));

try{
    $result=p50ym_map_channel(db(),$channelId,$profileId!==''?$profileId:null,(string)$user['id']);
    json_response(['ok'=>true]+$result);
}catch(InvalidArgumentException $error){
    json_response(['error'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('YouTube metrics mapping: '.p50_metrics_safe_error($error->getMessage()));
    json_response(['error'=>'Association YouTube impossible.'],500);
}
