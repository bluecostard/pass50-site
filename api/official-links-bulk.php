<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';

$user=auth_user();
require_role($user,'owner','admin');
require_method('POST');
p50_de_ensure_schema();
p50_de_sync_registry_from_state();

$input=json_input();
$action=(string)($input['action']??'save_profile');
$allowedPlatforms=['Instagram','TikTok','Facebook','YouTube','Snapchat','X','LinkedIn','Web'];
$officialStatuses=['verified','owner_verified','manual_verified','ok'];

function p50_links_local_validation(string $platform,string $url): array {
    $normalized=p50_de_normalize_social_url($platform,$url);
    if($normalized==='')return ['ok'=>false,'message'=>'URL invalide','normalizedUrl'=>''];
    if(!p50_platform_host_ok($platform,$normalized))return ['ok'=>false,'message'=>'Le domaine ne correspond pas à la plateforme','normalizedUrl'=>$normalized];
    if(!p50_de_direct_social_path($platform,$normalized))return ['ok'=>false,'message'=>'Le lien doit ouvrir directement le profil officiel','normalizedUrl'=>$normalized];
    return ['ok'=>true,'status'=>'manual_verified','message'=>'Lien direct validé manuellement','normalizedUrl'=>$normalized,'httpStatus'=>0,'nameScore'=>100];
}

function p50_links_state_profile_index(array $state,string $profileId): int {
    foreach((array)($state['profiles']??[]) as $index=>$profile){
        if(is_array($profile)&&(string)($profile['id']??'')===$profileId)return (int)$index;
    }
    return -1;
}

function p50_links_apply_to_state(array &$state,string $profileId,string $platform,string $url,string $status,string $message): bool {
    $index=p50_links_state_profile_index($state,$profileId);
    if($index<0)return false;
    $profile=&$state['profiles'][$index];
    $profile['links']=is_array($profile['links']??null)?$profile['links']:[];
    $profile['linkChecks']=is_array($profile['linkChecks']??null)?$profile['linkChecks']:[];
    $profile['platforms']=array_values(array_unique(array_merge((array)($profile['platforms']??[]),[$platform])));
    $profile['links'][$platform]=$url;
    $profile['linkChecks'][$platform]=[
        'status'=>$status,
        'checkedAt'=>gmdate(DATE_ATOM),
        'message'=>$message,
        'persistedServerSide'=>true,
    ];
    return true;
}

function p50_links_merge_verified_db(array &$state,string $profileId): int {
    $count=0;
    foreach(p50_de_social_links($profileId,true) as $link){
        $sourceTypes=(array)($link['sourceTypes']??[]);
        $owner=in_array('manual_owner',$sourceTypes,true);
        $status=$owner?'owner_verified':'manual_verified';
        if(p50_links_apply_to_state(
            $state,$profileId,(string)$link['platform'],(string)$link['url'],$status,
            $owner?'Compte officiel enregistré durablement par le propriétaire PASS50':'Compte officiel enregistré durablement par un administrateur PASS50'
        ))$count++;
    }
    return $count;
}

function p50_links_store_one(array &$state,array $user,string $profileId,string $platform,string $url,bool $confirmed,string $actionType='bulk_save'): array {
    $validation=p50_links_local_validation($platform,$url);
    if(!$validation['ok'])return ['ok'=>false,'profileId'=>$profileId,'platform'=>$platform,'url'=>$url,'error'=>$validation['message']];

    $normalized=(string)$validation['normalizedUrl'];
    $previous=p50_de_current_social_url($profileId,$platform);
    $sourceType=$confirmed?($user['role']==='owner'?'manual_owner':'manual_admin'):'manual_candidate';
    $sourceWeight=$confirmed?($user['role']==='owner'?100:98):78;
    $publicStatus=$confirmed?($user['role']==='owner'?'owner_verified':'manual_verified'):'pending';
    $message=$confirmed
        ?($user['role']==='owner'?'Compte officiel confirmé et enregistré durablement par le propriétaire PASS50':'Compte officiel confirmé et enregistré durablement par un administrateur PASS50')
        :'Lien enregistré durablement sur le serveur, en attente de confirmation officielle';

    $types=$confirmed?['manual_owner','manual_admin']:['manual_candidate'];
    $placeholders=implode(',',array_fill(0,count($types),'?'));
    $delete=db()->prepare("DELETE FROM p50_social_link_evidence WHERE profile_id=? AND platform=? AND source_type IN ($placeholders)");
    $delete->execute(array_merge([$profileId,$platform],$types));

    $validation['ok']=true;
    $validation['status']=$publicStatus;
    $validation['message']=$message;
    p50_de_add_social_evidence(
        $profileId,$platform,$normalized,$sourceType,
        (string)($user['display_name']??$user['email']??'PASS50'),'',
        $sourceWeight,$validation
    );
    p50_de_log_social_action($profileId,$platform,$actionType,$previous,$normalized,$user,[
        'confirmed'=>$confirmed,'transactional'=>true,'persistenceVersion'=>3,
    ]);
    p50_links_apply_to_state($state,$profileId,$platform,$normalized,$publicStatus,$message);
    return ['ok'=>true,'profileId'=>$profileId,'platform'=>$platform,'url'=>$normalized,'status'=>$publicStatus,'confirmed'=>$confirmed];
}

function p50_links_candidate_set(array $state,array $browserProfiles): array {
    $candidates=[];
    $put=static function(array &$target,string $profileId,string $platform,string $url,bool $confirmed,int $priority,string $at,string $source): void {
        $key=$profileId.'|'.$platform;
        $current=$target[$key]??null;
        if($current&&((int)$current['priority']>$priority||((int)$current['priority']===$priority&&strcmp((string)$current['at'],$at)>=0)))return;
        $target[$key]=compact('profileId','platform','url','confirmed','priority','at','source');
    };

    foreach($browserProfiles as $item){
        if(!is_array($item))continue;
        $profileId=trim((string)($item['profileId']??''));
        if($profileId==='')continue;
        foreach((array)($item['links']??[]) as $platform=>$link){
            $url='';$status='';
            if(is_array($link)){$url=trim((string)($link['url']??''));$status=(string)($link['status']??'');}
            else $url=trim((string)$link);
            if($url==='')continue;
            $confirmed=in_array($status,['verified','owner_verified','manual_verified','ok'],true);
            $put($candidates,$profileId,(string)$platform,$url,$confirmed,40,gmdate('Y-m-d H:i:s'),'browser');
        }
    }

    $stmt=db()->query("SELECT profile_id,platform,normalized_url,source_type,fetched_at,id
        FROM p50_social_link_evidence
        WHERE source_type IN ('manual_owner','manual_admin','manual_candidate')
        ORDER BY fetched_at DESC,id DESC");
    foreach($stmt->fetchAll() as $row){
        $confirmed=in_array((string)$row['source_type'],['manual_owner','manual_admin'],true);
        $put($candidates,(string)$row['profile_id'],(string)$row['platform'],(string)$row['normalized_url'],$confirmed,30,(string)$row['fetched_at'],'evidence');
    }

    $stmt=db()->query("SELECT profile_id,platform,new_url,action_type,created_at,id
        FROM p50_social_link_audit
        WHERE action_type IN ('save','confirm','restore','bulk_save','integrity_restore')
          AND new_url IS NOT NULL AND new_url<>''
        ORDER BY created_at DESC,id DESC");
    foreach($stmt->fetchAll() as $row){
        $confirmed=in_array((string)$row['action_type'],['confirm','restore','integrity_restore'],true);
        $put($candidates,(string)$row['profile_id'],(string)$row['platform'],(string)$row['new_url'],$confirmed,25,(string)$row['created_at'],'audit');
    }

    foreach((array)($state['profiles']??[]) as $profile){
        if(!is_array($profile)||empty($profile['id']))continue;
        $profileId=(string)$profile['id'];
        foreach((array)($profile['links']??[]) as $platform=>$url){
            $url=trim((string)$url;if($url==='')continue;
            $status=(string)(($profile['linkChecks']??[])[$platform]['status']??'');
            $confirmed=in_array($status,['verified','owner_verified','manual_verified','ok'],true);
            $put($candidates,$profileId,(string)$platform,$url,$confirmed,10,'','state');
        }
    }
    return array_values($candidates);
}

if(!in_array($action,['save_profile','integrity_sync'],true))json_response(['error'=>'Action inconnue.'],422);

$pdo=db();
$results=[];$errors=[];$touched=[];$restored=0;
$pdo->beginTransaction();
try{
    $state=p50_de_load_public_state_for_update();
    if(!$state)throw new RuntimeException('État public introuvable.');

    if($action==='save_profile'){
        $profileId=trim((string)($input['profileId']??''));
        if($profileId===''||p50_links_state_profile_index($state,$profileId)<0)throw new RuntimeException('Profil introuvable.');
        $confirmed=!empty($input['confirmedOfficial']);
        foreach((array)($input['links']??[]) as $platform=>$url){
            $platform=(string)$platform;$url=trim((string)$url;
            if($url==='')continue; // Un champ vide ne supprime plus jamais une donnée existante.
            if(!in_array($platform,$allowedPlatforms,true)){$errors[]=['profileId'=>$profileId,'platform'=>$platform,'error'=>'Plateforme non prise en charge'];continue;}
            $result=p50_links_store_one($state,$user,$profileId,$platform,$url,$confirmed,'bulk_save');
            if($result['ok']){$results[]=$result;$touched[$profileId]=true;}else $errors[]=$result;
        }
        if(!$results&&$errors)throw new RuntimeException((string)($errors[0]['error']??'Aucun lien valide.'));
    }else{
        $browserProfiles=array_slice((array)($input['profiles']??[]),0,500);
        foreach(p50_links_candidate_set($state,$browserProfiles) as $candidate){
            $profileId=(string)$candidate['profileId'];$platform=(string)$candidate['platform'];$url=(string)$candidate['url'];
            if(p50_links_state_profile_index($state,$profileId)<0||!in_array($platform,$allowedPlatforms,true))continue;
            $result=p50_links_store_one($state,$user,$profileId,$platform,$url,(bool)$candidate['confirmed'],'integrity_restore');
            if($result['ok']){$result['source']=$candidate['source'];$results[]=$result;$touched[$profileId]=true;$restored++;}else $errors[]=$result;
        }
    }

    foreach(array_keys($touched) as $profileId)p50_links_merge_verified_db($state,$profileId);
    $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;
    $state['officialLinksPersistence']=[
        'version'=>3,'updatedAt'=>gmdate(DATE_ATOM),'updatedBy'=>(string)$user['id'],
        'action'=>$action,'profilesUpdated'=>count($touched),'linksProcessed'=>count($results),
    ];
    p50_de_save_public_state($state,(string)$user['id'],false);
    $pdo->commit();

    $updates=[];
    foreach(array_keys($touched) as $profileId){
        $index=p50_links_state_profile_index($state,$profileId);
        if($index>=0)$updates[$profileId]=[
            'links'=>(array)($state['profiles'][$index]['links']??[]),
            'linkChecks'=>(array)($state['profiles'][$index]['linkChecks']??[]),
            'platforms'=>(array)($state['profiles'][$index]['platforms']??[]),
        ];
    }
    json_response([
        'ok'=>true,'action'=>$action,'stateRevision'=>(int)$state['stateRevision'],
        'profilesUpdated'=>count($touched),'linksProcessed'=>count($results),'restoredCount'=>$restored,
        'results'=>$results,'errors'=>$errors,'updates'=>$updates,
    ]);
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['error'=>$error->getMessage(),'details'=>$errors],422);
}
