<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';
require __DIR__.'/meta-oauth-assets.php';
require_method('POST');set_time_limit(45);p50mo_ensure_schema();

$user=auth_user();$userId=(string)$user['id'];$connection=p50mo_connection($userId);
if(!$connection)json_response(['error'=>'Aucun compte Meta connecté.'],409);
if(!in_array((string)$connection['status'],['active','reauthorization_required'],true))json_response(['error'=>'La connexion Meta doit être renouvelée.'],409);
try{
    $userToken=p50mo_decrypt((string)$connection['access_token_encrypted']);
    if($userToken==='')throw new RuntimeException('Jeton utilisateur Meta absent.');
    $discovery=p50mo_discover_authorized_assets($userToken);$warning=$discovery['warning'];$pdo=db();$pdo->beginTransaction();
    try{
        p50mo_replace_assets_for_user($userId,$discovery['assets']);
        $pdo->prepare("UPDATE p50_meta_oauth_connections SET status='active',last_error=?,last_refreshed_at=UTC_TIMESTAMP() WHERE user_id=?")
            ->execute([$warning!==null?substr((string)$warning,0,255):null,$userId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $autoMap=p50mo_auto_map_unmapped_assets($userId);
    json_response([
        'ok'=>true,'assets'=>count($discovery['assets']),'facebookPages'=>$discovery['pagesWithToken'],
        'selectedPages'=>$discovery['selectedPages'],'pagesWithoutToken'=>$discovery['pagesWithoutToken'],
        'autoMapped'=>(int)$autoMap['mapped'],'autoMapChecked'=>(int)$autoMap['checked'],
        'warning'=>$warning,'refreshedAt'=>gmdate(DATE_ATOM),
    ]);
}catch(Throwable $e){
    $message=$e->getMessage();error_log('Meta asset refresh: '.$message);
    if(str_contains(strtolower($message),'token')||str_contains(strtolower($message),'session')){
        db()->prepare("UPDATE p50_meta_oauth_connections SET status='reauthorization_required',last_error=? WHERE user_id=?")
            ->execute([substr($message,0,255),$userId]);
    }
    json_response(['error'=>'Impossible de relire les Pages Meta.','detail'=>substr($message,0,180)],502);
}
