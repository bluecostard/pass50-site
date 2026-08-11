<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/data-engine-core.php';

const P50_LINKS_BATCH_OWNER_VERSION='OFFICIAL-LINKS-BATCH-OWNER-V1.0';
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');if($raw===false||strlen($raw)>32768)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled']||strlen($secret)<32)json_response(['error'=>'Cron non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));if($dispatchId===''||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$fixed=[
    'gorsky'=>['Instagram'=>'https://www.instagram.com/gorsky/','TikTok'=>'https://www.tiktok.com/@gorsky'],
    'hassanhayek'=>['Instagram'=>'https://www.instagram.com/hassanhayek/','TikTok'=>'https://www.tiktok.com/@hassanhayek'],
    'holysheilla'=>['Instagram'=>'https://www.instagram.com/holysheilla/','TikTok'=>'https://www.tiktok.com/@holysheilla'],
    'jonathanmorrison'=>['Instagram'=>'https://www.instagram.com/jonathanmorrison13/'],
    'justecrepin'=>['Facebook'=>'https://www.facebook.com/Influenceurpositif'],
    'coachhamondchic'=>['TikTok'=>'https://www.tiktok.com/@coachhamond'],
    'ladysonia'=>[
        'Instagram'=>'https://www.instagram.com/ladysoniam/',
        'TikTok'=>'https://www.tiktok.com/@ladysoniam',
        'Facebook'=>'https://www.facebook.com/LadysoniaMabiala',
        'YouTube'=>'https://www.youtube.com/@LadyMABIALA',
    ],
];

function p50_batch_profile_index(array $state,string $normalizedName): int {
    foreach((array)($state['profiles']??[]) as $index=>$profile){
        if(p50_de_normalize_profile_name((string)($profile['name']??''))===$normalizedName)return (int)$index;
    }
    return -1;
}
function p50_batch_store(array &$profile,string $platform,string $url,string $source): array {
    $profileId=(string)$profile['id'];$normalized=p50_de_normalize_social_url($platform,$url);
    if($normalized===''||!p50_platform_host_ok($platform,$normalized)||!p50_de_direct_social_path($platform,$normalized))throw new RuntimeException('Lien invalide pour '.($profile['name']??$profileId).' / '.$platform.'.');
    $delete=db()->prepare("DELETE FROM p50_social_link_evidence WHERE profile_id=? AND platform=? AND source_type IN ('manual_owner','manual_admin')");$delete->execute([$profileId,$platform]);
    $validation=['ok'=>true,'status'=>'owner_verified','normalizedUrl'=>$normalized,'httpStatus'=>0,'nameScore'=>100,'message'=>'Compte officiel confirmé par le propriétaire PASS50'];
    p50_de_add_social_evidence($profileId,$platform,$normalized,'manual_owner','Propriétaire PASS50','',100,$validation);
    p50_de_log_social_action($profileId,$platform,$source==='owner_capture'?'confirm':'restore',(string)($profile['links'][$platform]??''),$normalized,['id'=>null,'role'=>'owner','display_name'=>'Propriétaire PASS50'],['source'=>$source,'permanentValidation'=>true,'version'=>P50_LINKS_BATCH_OWNER_VERSION]);
    $profile['links']=is_array($profile['links']??null)?$profile['links']:[];$profile['linkChecks']=is_array($profile['linkChecks']??null)?$profile['linkChecks']:[];$profile['platforms']=is_array($profile['platforms']??null)?$profile['platforms']:[];
    $profile['links'][$platform]=$normalized;$profile['linkChecks'][$platform]=['status'=>'owner_verified','checkedAt'=>gmdate(DATE_ATOM),'message'=>'Compte officiel confirmé et protégé par le propriétaire PASS50','persistedServerSide'=>true,'protectedBy'=>'PASS50-STATE-LINK-PROTECTION-V4.1'];$profile['platforms']=array_values(array_unique(array_merge($profile['platforms'],[$platform])));
    return ['profileId'=>$profileId,'name'=>(string)($profile['name']??$profileId),'platform'=>$platform,'url'=>$normalized,'source'=>$source];
}

$pdo=db();p50_de_ensure_schema();$pdo->beginTransaction();
try{
    $state=p50_de_load_public_state_for_update();if(!$state)throw new RuntimeException('État public introuvable.');$validated=[];
    foreach($fixed as $name=>$links){
        $index=p50_batch_profile_index($state,$name);if($index<0)throw new RuntimeException('Fiche introuvable : '.$name.'.');
        foreach($links as $platform=>$url)$validated[]=p50_batch_store($state['profiles'][$index],$platform,$url,'owner_capture');
    }
    if(count($validated)!==13)throw new RuntimeException('Les treize comptes fournis n’ont pas tous été validés.');
    $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;$state['officialLinksBatchOwner']=['version'=>P50_LINKS_BATCH_OWNER_VERSION,'updatedAt'=>gmdate(DATE_ATOM),'validated'=>count($validated)];
    p50_de_save_public_state($state,null,false);$pdo->commit();
    json_response(['ok'=>true,'version'=>P50_LINKS_BATCH_OWNER_VERSION,'dispatchId'=>$dispatchId,'validated'=>$validated,'validatedCount'=>count($validated),'publicStateRevision'=>(int)$state['stateRevision'],'publicStateWrites'=>1]);
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();json_response(['error'=>'Validation groupée interrompue.','detail'=>$error->getMessage()],500);}
