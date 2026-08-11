<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/data-engine-core.php';

const P50_DBZ_VALIDATION_VERSION='DBZ-PROFILE-VALIDATION-V1.0';

header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Méthode refusée.'],405);
$raw=file_get_contents('php://input');
if($raw===false||strlen($raw)>32768)json_response(['error'=>'Corps invalide.'],413);
$cfg=p50_mo_config();$secret=(string)$cfg['cronSecret'];
if(!$cfg['enabled']||strlen($secret)<32)json_response(['error'=>'Cron non configuré.'],503);
$timestamp=trim((string)($_SERVER['HTTP_X_P50_TIMESTAMP']??''));
$signature=strtolower(trim((string)($_SERVER['HTTP_X_P50_SIGNATURE']??'')));
if(!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>300)json_response(['error'=>'Horodatage refusé.'],401);
if(!p50_mo_verify_cron_signature($secret,$timestamp,$raw,$signature))json_response(['error'=>'Signature refusée.'],401);
$input=json_decode($raw,true);if(!is_array($input))json_response(['error'=>'JSON invalide.'],422);
$dispatchId=trim((string)($input['dispatchId']??''));
if($dispatchId===''||strlen($dispatchId)>120||!preg_match('/^[A-Za-z0-9._-]+$/',$dispatchId))json_response(['error'=>'dispatchId invalide.'],422);

$links=[
    'Instagram'=>'https://www.instagram.com/dbz_2_/',
    'TikTok'=>'https://www.tiktok.com/@dbz.07',
    'Facebook'=>'https://www.facebook.com/profile.php?id=61575109614293',
];

$pdo=db();p50_de_ensure_schema();
$pdo->beginTransaction();
try{
    $state=p50_de_load_public_state_for_update();
    if(!$state)throw new RuntimeException('État public introuvable.');
    $profileIndex=-1;
    foreach((array)($state['profiles']??[]) as $index=>$profile){
        $id=strtolower(trim((string)($profile['id']??'')));
        $name=strtolower(trim((string)($profile['name']??'')));
        if($id==='dbz'||$name==='dbz'){$profileIndex=(int)$index;break;}
    }
    if($profileIndex<0)throw new RuntimeException('Fiche DBZ introuvable.');
    $profileId=(string)$state['profiles'][$profileIndex]['id'];
    $profile=&$state['profiles'][$profileIndex];
    $profile['links']=is_array($profile['links']??null)?$profile['links']:[];
    $profile['linkChecks']=is_array($profile['linkChecks']??null)?$profile['linkChecks']:[];
    $profile['platforms']=is_array($profile['platforms']??null)?$profile['platforms']:[];
    $validated=[];

    foreach($links as $platform=>$url){
        $normalized=p50_de_normalize_social_url($platform,$url);
        if($normalized===''||!p50_platform_host_ok($platform,$normalized)||!p50_de_direct_social_path($platform,$normalized)){
            throw new RuntimeException('Lien DBZ invalide pour '.$platform.'.');
        }
        $delete=$pdo->prepare("DELETE FROM p50_social_link_evidence WHERE profile_id=? AND platform=? AND source_type IN ('manual_owner','manual_admin')");
        $delete->execute([$profileId,$platform]);
        $validation=['ok'=>true,'status'=>'owner_verified','normalizedUrl'=>$normalized,'httpStatus'=>0,'nameScore'=>100,'message'=>'Compte officiel confirmé par le propriétaire PASS50'];
        p50_de_add_social_evidence($profileId,$platform,$normalized,'manual_owner','Propriétaire PASS50','',100,$validation);
        p50_de_log_social_action($profileId,$platform,'confirm',(string)($profile['links'][$platform]??''),$normalized,[
            'id'=>'owner-pass50','role'=>'owner','display_name'=>'Propriétaire PASS50',
        ],['confirmed'=>true,'permanentValidation'=>true,'version'=>P50_DBZ_VALIDATION_VERSION]);
        $profile['links'][$platform]=$normalized;
        $profile['linkChecks'][$platform]=[
            'status'=>'owner_verified','checkedAt'=>gmdate(DATE_ATOM),
            'message'=>'Compte officiel protégé après validation du propriétaire PASS50',
            'persistedServerSide'=>true,'protectedBy'=>'PASS50-STATE-LINK-PROTECTION-V4.1',
        ];
        $profile['platforms'][]=$platform;
        $validated[$platform]=$normalized;
    }
    $profile['platforms']=array_values(array_unique(array_map('strval',$profile['platforms'])));
    $profile['officialLinksValidatedAt']=gmdate(DATE_ATOM);
    $profile['officialLinksValidationVersion']=P50_DBZ_VALIDATION_VERSION;
    $state['stateRevision']=max(0,(int)($state['stateRevision']??0))+1;
    $state['dbzProfileValidation']=[
        'version'=>P50_DBZ_VALIDATION_VERSION,'profileId'=>$profileId,
        'updatedAt'=>gmdate(DATE_ATOM),'platforms'=>array_keys($validated),
    ];
    p50_de_save_public_state($state,null,false);
    $pdo->commit();
    json_response([
        'ok'=>true,'version'=>P50_DBZ_VALIDATION_VERSION,'dispatchId'=>$dispatchId,
        'profileId'=>$profileId,'name'=>(string)($profile['name']??'DBZ'),
        'validatedLinks'=>$validated,'validatedCount'=>count($validated),
        'youtubePreservedEmpty'=>empty($profile['links']['YouTube']),
        'publicStateRevision'=>(int)$state['stateRevision'],'publicStateWrites'=>1,
    ]);
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['error'=>'Validation DBZ interrompue.','detail'=>$error->getMessage()],500);
}
