<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/youtube-metrics-bridge-core.php';
require __DIR__.'/metrics-orchestrator-core.php';
require_method('POST');

$user=auth_user();
require_role($user,'owner','admin');
p50_de_sync_registry_from_state();

$input=json_input();
$channelId=trim((string)($input['channelId']??''));
$profileId=trim((string)($input['profileId']??''));

try{
    $result=p50ym_map_channel(db(),$channelId,$profileId!==''?$profileId:null,(string)$user['id']);
    $queued=null;$deferred=false;
    if($profileId!==''){
        try{$queued=p50_mo_enqueue_profile(db(),$profileId,'YouTube','p0',['reason'=>'oauth_mapping','priorityOverride'=>5,'contentLimit'=>5,'dispatchId'=>'youtube-map-'.substr(hash('sha256',$channelId),0,16)]);}
        catch(Throwable $queueError){$deferred=true;error_log('YouTube mapping queue deferred: '.p50_metrics_safe_error($queueError->getMessage()));}
    }
    json_response(['ok'=>true,'collectionQueued'=>$queued,'collectionDeferred'=>$deferred]+$result);
}catch(InvalidArgumentException $error){
    json_response(['error'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('YouTube metrics mapping: '.p50_metrics_safe_error($error->getMessage()));
    json_response(['error'=>'Association YouTube impossible.'],500);
}
