<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');
set_time_limit(240);
p50_de_ensure_schema();
p50_de_sync_registry_from_state();
$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$limit=max(1,min(5,(int)($in['limit']??5)));
$deep=!array_key_exists('deep',$in)||!empty($in['deep']);
$publish=!array_key_exists('publishVerified',$in)||!empty($in['publishVerified']);
$profiles=p50_de_profiles_for_collection($limit,$profileId!==''?$profileId:null);
$results=[];$totalFound=0;$totalVerified=0;$processedIds=[];
foreach($profiles as $profile){
    $run=p50_de_begin_run((string)$profile['profile_id'],'auto_enrichment_v22',$user['id'],['deep'=>$deep]);
    try{
        $imported=p50_de_collect_state_links($profile);
        $importedFacts=p50_de_collect_state_facts($profile);
        $enrichment=p50_de_collect_enrichment($profile,$deep);
        $found=$imported+$importedFacts+(int)($enrichment['found']??0);
        $youtube=p50_de_collect_youtube_activity($profile);
        $found+=(int)($youtube['found']??0);
        $socialActivity=p50_de_collect_social_activity($profile);
        $found+=(int)($socialActivity['found']??0);
        $verified=p50_de_profile_verified_count((string)$profile['profile_id'])+(int)($youtube['verified']??0)+(int)($socialActivity['verified']??0);
        if($publish)p50_de_publish_profile((string)$profile['profile_id'],$user['id']);
        p50_de_finish_run($run['id'],'success',$found,$verified,null,['enrichment'=>$enrichment,'youtube'=>$youtube,'socialActivity'=>$socialActivity,'stateLinksImported'=>$imported,'stateFactsImported'=>$importedFacts]);
        $results[]=['profileId'=>$profile['profile_id'],'name'=>$profile['public_name'],'status'=>'success','found'=>$found,'verified'=>$verified,'details'=>$enrichment];
        $processedIds[]=(string)$profile['profile_id'];$totalFound+=$found;$totalVerified+=$verified;
    }catch(Throwable $e){
        error_log('PASS50 data collect '.$profile['profile_id'].': '.$e->getMessage());
        p50_de_finish_run($run['id'],'error',0,0,$e->getMessage());
        $results[]=['profileId'=>$profile['profile_id'],'name'=>$profile['public_name'],'status'=>'error','error'=>'Collecte impossible pour ce profil.'];
        $processedIds[]=(string)$profile['profile_id'];
    }
}
$remainingNeverCollected=(int)db()->query("SELECT COUNT(*) FROM p50_profile_registry r LEFT JOIN (SELECT DISTINCT profile_id FROM p50_collection_runs) x ON x.profile_id=r.profile_id WHERE r.alive=1 AND x.profile_id IS NULL")->fetchColumn();
json_response(['ok'=>true,'processed'=>count($profiles),'processedIds'=>$processedIds,'found'=>$totalFound,'verified'=>$totalVerified,'remainingNeverCollected'=>$remainingNeverCollected,'nextOffset'=>0,'results'=>$results,'hub'=>p50_de_hub_payload()]);
