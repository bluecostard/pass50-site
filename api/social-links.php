<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
$user=auth_user();
require_role($user,'owner','admin');
p50_de_ensure_schema();
p50_de_sync_registry_from_state();

if($_SERVER['REQUEST_METHOD']==='GET'){
    $profileId=trim((string)($_GET['profileId']??''));
    if($profileId==='')json_response(['error'=>'Profil manquant.'],422);
    $profiles=p50_de_registry_profiles($profileId,1,0);
    if(!$profiles)json_response(['error'=>'Profil introuvable.'],404);
    $stmt=db()->prepare('SELECT DISTINCT platform FROM p50_social_link_evidence WHERE profile_id=?');
    $stmt->execute([$profileId]);
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingPlatform)p50_de_rebuild_social_link($profileId,(string)$existingPlatform);
    p50_de_publish_profile($profileId,$user['id']);
    json_response(['ok'=>true,'profile'=>$profiles[0],'links'=>p50_de_social_links($profileId,false),'threshold'=>p50_de_threshold()]);
}
require_method('POST');
$in=json_input();
$action=(string)($in['action']??'save');
$profileId=trim((string)($in['profileId']??''));
$platform=trim((string)($in['platform']??''));
if($profileId===''||$platform==='')json_response(['error'=>'Profil et plateforme requis.'],422);
$profiles=p50_de_registry_profiles($profileId,1,0);
if(!$profiles)json_response(['error'=>'Profil introuvable.'],404);
$profile=$profiles[0];
if($action==='reject'){
    $stmt=db()->prepare("UPDATE p50_social_links SET status='rejected',confidence=0,verified_at=NULL,updated_at=NOW() WHERE profile_id=? AND platform=?");
    $stmt->execute([$profileId,$platform]);
    p50_de_publish_profile($profileId,$user['id']);
    json_response(['ok'=>true,'links'=>p50_de_social_links($profileId,false)]);
}
if($action==='delete'){
    $pdo=db();
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('DELETE FROM p50_social_link_evidence WHERE profile_id=? AND platform=?');
        $stmt->execute([$profileId,$platform]);
        $stmt=$pdo->prepare('DELETE FROM p50_social_links WHERE profile_id=? AND platform=?');
        $stmt->execute([$profileId,$platform]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    p50_de_publish_profile($profileId,$user['id']);
    json_response(['ok'=>true,'deleted'=>true,'links'=>p50_de_social_links($profileId,false)]);
}
$url=trim((string)($in['url']??''));
if($url==='')json_response(['error'=>'URL requise.'],422);
$validation=p50_de_validate_social_url($platform,$url,(string)$profile['public_name'],(string)$profile['handle']);
if($validation['normalizedUrl']==='')json_response(['error'=>$validation['message']??'URL invalide.','validation'=>$validation],422);
if(in_array($validation['status'],['wrong_platform','generic_or_content','invalid'],true))json_response(['error'=>$validation['message'],'validation'=>$validation],422);
$confirmed=!empty($in['confirmedOfficial']);
$sourceType=$confirmed?($user['role']==='owner'?'manual_owner':'manual_admin'):'manual_candidate';
$weight=$confirmed?($user['role']==='owner'?100:98):75;
if($confirmed&&!empty($in['replaceExisting'])){
    // Un remplacement validé doit devenir la seule preuve manuelle active pour
    // cette plateforme. Sinon l'ancien et le nouveau lien obtiennent le même
    // score et la fiche passe artificiellement en conflit.
    $types=$user['role']==='owner'?['manual_owner','manual_admin']:['manual_admin'];
    $placeholders=implode(',',array_fill(0,count($types),'?'));
    $stmt=db()->prepare("DELETE FROM p50_social_link_evidence WHERE profile_id=? AND platform=? AND source_type IN ($placeholders)");
    $stmt->execute(array_merge([$profileId,$platform],$types));
}
p50_de_add_social_evidence($profileId,$platform,$validation['normalizedUrl'],$sourceType,$user['display_name']??$user['email'],'',$weight,$validation);
if($confirmed)p50_de_publish_profile($profileId,$user['id']);
json_response(['ok'=>true,'confirmed'=>$confirmed,'validation'=>$validation,'links'=>p50_de_social_links($profileId,false)]);
