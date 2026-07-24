<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');
set_time_limit(180);
p50_de_ensure_schema();
p50_de_sync_registry_from_state();
$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$limit=max(1,min(10,(int)($in['limit']??5)));
$offset=max(0,(int)($in['offset']??0));
$publish=!array_key_exists('publishVerified',$in)||!empty($in['publishVerified']);
$profiles=p50_de_registry_profiles($profileId!==''?$profileId:null,$limit,$offset);
$results=[];$totalFound=0;$totalVerified=0;
foreach($profiles as $profile){
    $run=p50_de_begin_run((string)$profile['profile_id'],'wikidata_wikipedia',$user['id'],['offset'=>$offset]);
    try{
        $imported=p50_de_collect_state_links($profile);
        $importedFacts=p50_de_collect_state_facts($profile);
        $collected=p50_de_collect_wikidata($profile);
        $found=$imported+$importedFacts+(int)($collected['found']??0);
        $youtube=p50_de_collect_youtube_activity($profile);
        $found+=(int)($youtube['found']??0);
        $verified=p50_de_profile_verified_count((string)$profile['profile_id'])+(int)($youtube['verified']??0);
        if($publish)p50_de_publish_profile((string)$profile['profile_id'],$user['id']);
        p50_de_finish_run($run['id'],'success',$found,$verified,null,['collector'=>$collected,'youtube'=>$youtube,'stateLinksImported'=>$imported,'stateFactsImported'=>$importedFacts]);
        $results[]=['profileId'=>$profile['profile_id'],'name'=>$profile['public_name'],'status'=>'success','found'=>$found,'verified'=>$verified,'details'=>$collected];
        $totalFound+=$found;$totalVerified+=$verified;
    }catch(Throwable $e){
        error_log('PASS50 data collect '.$profile['profile_id'].': '.$e->getMessage());
        p50_de_finish_run($run['id'],'error',0,0,$e->getMessage());
        $results[]=['profileId'=>$profile['profile_id'],'name'=>$profile['public_name'],'status'=>'error','error'=>'Collecte impossible pour ce profil.'];
    }
}
$algorithm=p50_s12_calculate_all($user['id']);
json_response(['ok'=>true,'processed'=>count($profiles),'found'=>$totalFound,'verified'=>$totalVerified,'nextOffset'=>$profileId!==''?null:$offset+count($profiles),'results'=>$results,'algorithm'=>$algorithm,'hub'=>p50_de_hub_payload()]);
