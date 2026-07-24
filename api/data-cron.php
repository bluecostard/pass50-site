<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
set_time_limit(240);
p50_de_ensure_schema();

global $config;
$expected=(string)($config['data_engine']['cron_token']??'');
$provided=(string)($_SERVER['HTTP_X_PASS50_CRON']??($_GET['token']??''));
if($expected===''||$provided===''||!hash_equals($expected,$provided))json_response(['error'=>'Jeton cron invalide.'],403);

$action=(string)($_GET['action']??'cycle');
$batch=max(1,min(10,(int)($config['data_engine']['batch_size']??5)));
if($action==='snapshot'){
    $count=p50_de_capture_snapshots((string)($_GET['period']??'2H'));
    json_response(['ok'=>true,'action'=>'snapshot','captured'=>$count]);
}
if(!in_array($action,['collect','cycle'],true))json_response(['error'=>'Action cron inconnue.'],422);

p50_de_sync_registry_from_state();
$offset=max(0,(int)p50_de_get_setting('cron_offset',0));
$profiles=p50_de_registry_profiles(null,$batch,$offset);
if(!$profiles&&$offset>0){$offset=0;$profiles=p50_de_registry_profiles(null,$batch,0);}
$results=[];$found=0;$verified=0;
foreach($profiles as $profile){
    $run=p50_de_begin_run((string)$profile['profile_id'],'cron_wikidata_wikipedia',null,['offset'=>$offset]);
    try{
        $stateLinks=p50_de_collect_state_links($profile);
        $stateFacts=p50_de_collect_state_facts($profile);
        $collected=p50_de_collect_wikidata($profile);
        $profileFound=$stateLinks+$stateFacts+(int)($collected['found']??0);
        $youtube=p50_de_collect_youtube_activity($profile);
        $profileFound+=(int)($youtube['found']??0);
        $profileVerified=p50_de_profile_verified_count((string)$profile['profile_id'])+(int)($youtube['verified']??0);
        p50_de_publish_profile((string)$profile['profile_id'],null);
        p50_de_finish_run($run['id'],'success',$profileFound,$profileVerified,null,['collector'=>$collected,'youtube'=>$youtube]);
        $found+=$profileFound;$verified+=$profileVerified;
        $results[]=['profileId'=>$profile['profile_id'],'status'=>'success','found'=>$profileFound,'verified'=>$profileVerified];
    }catch(Throwable $e){
        error_log('PASS50 cron '.$profile['profile_id'].': '.$e->getMessage());
        p50_de_finish_run($run['id'],'error',0,0,$e->getMessage());
        $results[]=['profileId'=>$profile['profile_id'],'status'=>'error'];
    }
}
$nextOffset=$offset+count($profiles);
$total=(int)db()->query('SELECT COUNT(*) FROM p50_profile_registry WHERE alive=1 AND eligible=1')->fetchColumn();
if($nextOffset>=$total)$nextOffset=0;
p50_de_set_setting('cron_offset',$nextOffset);
$algorithm=p50_s12_calculate_all(null);
$snapshots=$action==='cycle'?p50_de_capture_snapshots('2H'):0;
json_response(['ok'=>true,'action'=>$action,'processed'=>count($profiles),'found'=>$found,'verified'=>$verified,'snapshots'=>$snapshots,'algorithm'=>$algorithm,'offset'=>$offset,'nextOffset'=>$nextOffset,'results'=>$results]);
