<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
$user=auth_user();
require_role($user,'owner','admin');
p50_de_ensure_schema();
p50_de_sync_registry_from_state();
require_method('POST');
$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$scope=(string)($in['scope']??($profileId!==''?'profile':'all'));
$state=p50_de_load_public_state();
$stateMap=p50_de_profile_state_map($state);
$profiles=$scope==='all'?p50_de_registry_profiles(null,1000,0,false):p50_de_registry_profiles($profileId,1,0,false);
$restored=[];$skipped=[];
foreach($profiles as $profile){
    $pid=(string)$profile['profile_id'];
    $candidates=[];
    // 1. Dernières preuves manuelles encore en base.
    $stmt=db()->prepare("SELECT platform,normalized_url,source_type,fetched_at FROM p50_social_link_evidence WHERE profile_id=? AND source_type IN ('manual_owner','manual_admin','manual_candidate') ORDER BY fetched_at DESC,id DESC");
    $stmt->execute([$pid]);
    foreach($stmt->fetchAll() as $row){$platform=(string)$row['platform'];if(!isset($candidates[$platform]))$candidates[$platform]=['url'=>(string)$row['normalized_url'],'source'=>'evidence','at'=>(string)$row['fetched_at']];}
    // 2. Historique immuable V22.7.
    $stmt=db()->prepare("SELECT platform,new_url,created_at FROM p50_social_link_audit WHERE profile_id=? AND action_type IN ('save','confirm','restore') AND new_url IS NOT NULL AND new_url<>'' ORDER BY created_at DESC,id DESC");
    $stmt->execute([$pid]);
    foreach($stmt->fetchAll() as $row){$platform=(string)$row['platform'];$at=(string)$row['created_at'];if(!isset($candidates[$platform])||$at>($candidates[$platform]['at']??''))$candidates[$platform]=['url'=>(string)$row['new_url'],'source'=>'audit','at'=>$at];}
    // 3. Liens encore présents dans l'état public / navigateur synchronisé.
    foreach((array)($stateMap[$pid]['links']??[]) as $platform=>$url){if(!isset($candidates[$platform]))$candidates[(string)$platform]=['url'=>(string)$url,'source'=>'state','at'=>''];}
    foreach($candidates as $platform=>$candidate){
        $url=(string)$candidate['url'];
        $validation=p50_de_validate_social_url($platform,$url,(string)$profile['public_name'],(string)$profile['handle']);
        if(($validation['normalizedUrl']??'')===''||in_array($validation['status']??'',['wrong_platform','generic_or_content','invalid'],true)){$skipped[]=['profileId'=>$pid,'platform'=>$platform,'url'=>$url,'reason'=>$validation['message']??'Lien invalide'];continue;}
        $normalized=p50_de_normalize_social_url($platform,$url) ?: (string)$validation['normalizedUrl'];
        $validation['ok']=true;$validation['status']=$user['role']==='owner'?'owner_verified':'manual_verified';$validation['message']='Lien restauré depuis les sauvegardes PASS50';
        p50_de_add_social_evidence($pid,$platform,$normalized,$user['role']==='owner'?'manual_owner':'manual_admin',$user['display_name']??$user['email'],'', $user['role']==='owner'?100:98,$validation);
        p50_de_log_social_action($pid,$platform,'restore',p50_de_current_social_url($pid,$platform),$normalized,$user,['source'=>$candidate['source']]);
        $restored[]=['profileId'=>$pid,'profileName'=>$profile['public_name'],'platform'=>$platform,'url'=>$normalized,'source'=>$candidate['source']];
    }
    if($candidates)p50_de_publish_profile($pid,$user['id']);
}
json_response(['ok'=>true,'scope'=>$scope,'restoredCount'=>count($restored),'skippedCount'=>count($skipped),'restored'=>$restored,'skipped'=>$skipped]);
