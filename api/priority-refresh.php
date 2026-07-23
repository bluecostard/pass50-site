<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('POST');
$user=auth_user();require_role($user,'owner','admin');set_time_limit(900);
p50_de_ensure_schema();p50_de_sync_registry_from_state();
$registry=p50_de_registry_profiles(null,1000,0,false);$profiles=array_values(array_filter($registry,static fn($p)=>p50_de_is_priority_profile((string)$p['profile_id'])));
$results=[];$found=0;$verified=0;$classable=0;
foreach($profiles as $profile){
    $id=(string)$profile['profile_id'];$run=p50_de_begin_run($id,'priority_wave_v22',$user['id'],['deep'=>true,'wave'=>'V22-16']);
    try{
        $profileFound=p50_de_collect_state_links($profile)+p50_de_collect_state_facts($profile)+p50_de_collect_curated_evidence_v221($profile);
        $enrichment=p50_de_collect_enrichment($profile,true);$profileFound+=(int)($enrichment['found']??0);
        $youtube=p50_de_collect_youtube_activity($profile);$profileFound+=(int)($youtube['found']??0);
        $social=p50_de_collect_social_activity($profile);$profileFound+=(int)($social['found']??0);
        $profileVerified=p50_de_profile_verified_count($id)+(int)($youtube['verified']??0)+(int)($social['verified']??0);
        p50_de_publish_profile($id,$user['id']);$trend=p50_de_compute_trend_score($id);if(!empty($trend['classable']))$classable++;
        p50_de_finish_run($run['id'],'success',$profileFound,$profileVerified,null,['trend'=>$trend,'enrichment'=>$enrichment,'youtube'=>$youtube,'social'=>$social]);
        $results[]=['profileId'=>$id,'name'=>$profile['public_name'],'status'=>'success','found'=>$profileFound,'verified'=>$profileVerified,'trend'=>$trend];$found+=$profileFound;$verified+=$profileVerified;
    }catch(Throwable $e){error_log('PASS50 priority V22 '.$id.': '.$e->getMessage());p50_de_finish_run($run['id'],'error',0,0,$e->getMessage());$results[]=['profileId'=>$id,'name'=>$profile['public_name'],'status'=>'error'];}
}
p50_de_capture_snapshots('2H');
json_response(['ok'=>true,'wave'=>'V22-16','processed'=>count($profiles),'found'=>$found,'verified'=>$verified,'classable'=>$classable,'results'=>$results,'hub'=>p50_de_hub_payload()]);
