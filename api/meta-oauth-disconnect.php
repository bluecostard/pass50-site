<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';
require_method('POST');
$user=auth_user();p50mo_ensure_schema();$userId=(string)$user['id'];$connection=p50mo_connection($userId);$revoked=false;$warning=null;
if($connection){
    try{$token=p50mo_decrypt((string)$connection['access_token_encrypted']);if($token!==''){$response=p50mo_http('https://graph.facebook.com/me/permissions','DELETE',['access_token'=>$token,'appsecret_proof'=>p50mo_proof($token)]);$revoked=$response['status']>=200&&$response['status']<300;if(!$revoked)$warning='Connexion locale supprimée, mais Meta n’a pas confirmé la révocation.';}}
    catch(Throwable $e){error_log('Meta OAuth revoke: '.$e->getMessage());$warning='Connexion locale supprimée, mais la révocation Meta n’a pas pu être confirmée.';}
}
$pdo=db();$pdo->beginTransaction();try{
    $assets=p50mo_assets($userId);foreach($assets as $asset){if(empty($asset['profile_id']))continue;$stmt=$pdo->prepare("DELETE FROM p50_live_streams WHERE profile_id=? AND platform=? AND source='meta_authorized'");$stmt->execute([(string)$asset['profile_id'],(string)$asset['platform']]);}
    $pdo->prepare('DELETE FROM p50_meta_oauth_assets WHERE user_id=?')->execute([$userId]);$pdo->prepare('DELETE FROM p50_meta_oauth_connections WHERE user_id=?')->execute([$userId]);$pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
json_response(['ok'=>true,'connected'=>false,'revokedAtMeta'=>$revoked,'warning'=>$warning]);
