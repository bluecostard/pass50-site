<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';
require __DIR__.'/data-engine-core.php';
require_method('POST');

$user=auth_user();
require_role($user,'owner','admin');
p50mo_ensure_schema();
p50_de_sync_registry_from_state();

$input=json_input();
$platform=trim((string)($input['platform']??''));
$assetId=trim((string)($input['assetId']??''));
$profileId=trim((string)($input['profileId']??''));

if(!in_array($platform,['Facebook','Instagram'],true))json_response(['error'=>'Plateforme Meta invalide.'],422);
if($assetId===''||!preg_match('/^[A-Za-z0-9_-]{2,100}$/',$assetId))json_response(['error'=>'Identifiant de compte Meta invalide.'],422);
if($profileId!==''&&!preg_match('/^[A-Za-z0-9._:-]{1,120}$/',$profileId))json_response(['error'=>'Identifiant de fiche PASS50 invalide.'],422);

$userId=(string)$user['id'];
$stmt=db()->prepare('SELECT platform,asset_id,parent_page_id FROM p50_meta_oauth_assets WHERE user_id=? AND platform=? AND asset_id=? LIMIT 1');
$stmt->execute([$userId,$platform,$assetId]);
$asset=$stmt->fetch();
if(!is_array($asset))json_response(['error'=>'Compte Meta introuvable pour cette connexion.'],404);

$profile=null;
if($profileId!==''){
    $rows=p50_de_registry_profiles($profileId,1,0,false);
    if(!$rows)json_response(['error'=>'Fiche PASS50 introuvable.'],404);
    $profile=$rows[0];
}

$pdo=db();$pdo->beginTransaction();
try{
    $value=$profileId!==''?$profileId:null;
    $updated=0;
    if($platform==='Facebook'){
        $update=$pdo->prepare('UPDATE p50_meta_oauth_assets SET profile_id=? WHERE user_id=? AND ((platform=\'Facebook\' AND asset_id=?) OR (platform=\'Instagram\' AND parent_page_id=?))');
        $update->execute([$value,$userId,$assetId,$assetId]);
        $updated=$update->rowCount();
    }else{
        $parent=trim((string)($asset['parent_page_id']??''));
        if($parent!==''){
            $update=$pdo->prepare('UPDATE p50_meta_oauth_assets SET profile_id=? WHERE user_id=? AND ((platform=\'Facebook\' AND asset_id=?) OR (platform=\'Instagram\' AND parent_page_id=?))');
            $update->execute([$value,$userId,$parent,$parent]);
            $updated=$update->rowCount();
        }else{
            $update=$pdo->prepare('UPDATE p50_meta_oauth_assets SET profile_id=? WHERE user_id=? AND platform=\'Instagram\' AND asset_id=?');
            $update->execute([$value,$userId,$assetId]);
            $updated=$update->rowCount();
        }
    }
    $pdo->commit();
    json_response([
        'ok'=>true,
        'profileId'=>$value,
        'profileName'=>$profile?(string)$profile['public_name']:null,
        'updatedAssets'=>$updated,
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('Meta asset mapping: '.$e->getMessage());
    json_response(['error'=>'Association Meta impossible.'],500);
}
