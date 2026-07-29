<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';
require_method('GET');
$user=auth_user();p50mo_ensure_schema();$userId=(string)$user['id'];$connection=p50mo_connection($userId);
if(!$connection)json_response(['ok'=>true,'connected'=>false,'assets'=>[]]);
$assets=array_map(static fn($asset)=>[
    'platform'=>(string)$asset['platform'],'id'=>(string)$asset['asset_id'],'profileId'=>$asset['profile_id']?:null,
    'name'=>(string)$asset['asset_name'],'username'=>(string)$asset['username'],'profileUrl'=>(string)($asset['profile_url']??''),
    'pictureUrl'=>(string)($asset['picture_url']??''),'parentPageId'=>$asset['parent_page_id']?:null,
    'mapped'=>!empty($asset['profile_id']),'lastCheckedAt'=>$asset['last_checked_at']?(string)$asset['last_checked_at'].'Z':null,
    'lastError'=>$asset['last_error']?:null,
],p50mo_assets($userId));
$expires=(string)($connection['token_expires_at']??'');$expiresTs=$expires!==''?strtotime($expires.' UTC'):false;
json_response(['ok'=>true,'connected'=>in_array((string)$connection['status'],['active','reauthorization_required'],true),'status'=>$connection['status'],'account'=>['id'=>$connection['meta_user_id'],'name'=>$connection['meta_user_name']],'scopes'=>preg_split('/\s+/',trim((string)$connection['scopes']))?:[],'tokenExpiresAt'=>$expires!==''?$expires.'Z':null,'tokenExpired'=>$expiresTs===false||$expiresTs<=time(),'requiresReauthorization'=>$connection['status']==='reauthorization_required','assets'=>$assets,'connectedAt'=>(string)$connection['connected_at'].'Z']);
