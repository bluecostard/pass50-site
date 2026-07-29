<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';
require_method('GET');

function p50_meta_safe_configuration_status(): array {
    global $config;
    $oauth=is_array($config['meta_oauth']??null)?$config['meta_oauth']:[];
    $google=is_array($config['google_oauth']??null)?$config['google_oauth']:[];
    $value=static function(array $source,string $key,string $environment): string {
        $configured=trim((string)($source[$key]??''));
        return $configured!==''?$configured:trim((string)(getenv($environment)?:''));
    };
    $appId=$value($oauth,'app_id','META_APP_ID');
    $appSecret=$value($oauth,'app_secret','META_APP_SECRET');
    $redirectUri=$value($oauth,'redirect_uri','META_REDIRECT_URI');
    $graphVersion=$value($oauth,'graph_version','META_GRAPH_VERSION');
    $encryptionKey=$value($oauth,'token_encryption_key','PASS50_TOKEN_ENCRYPTION_KEY');
    if($encryptionKey==='')$encryptionKey=trim((string)($google['token_encryption_key']??''));
    $checks=[
        'appIdConfigured'=>$appId!=='',
        'appSecretConfigured'=>$appSecret!=='',
        'redirectUriValid'=>$redirectUri!==''&&filter_var($redirectUri,FILTER_VALIDATE_URL)&&str_starts_with($redirectUri,'https://'),
        'graphVersionValid'=>(bool)preg_match('/^v\d+\.\d+$/',$graphVersion),
        'encryptionKeyConfigured'=>$encryptionKey!=='',
    ];
    $labels=[
        'appIdConfigured'=>'App ID Meta',
        'appSecretConfigured'=>'App Secret Meta',
        'redirectUriValid'=>'URI de redirection HTTPS',
        'graphVersionValid'=>'version Graph API',
        'encryptionKeyConfigured'=>'clé de chiffrement',
    ];
    $missing=[];foreach($checks as $key=>$ok)if(!$ok)$missing[]=$labels[$key];
    return $checks+[
        'ready'=>!in_array(false,$checks,true),
        'missing'=>$missing,
        'redirectUri'=>$redirectUri!==''?$redirectUri:null,
        'graphVersion'=>$graphVersion!==''?$graphVersion:null,
    ];
}

$user=auth_user();
p50mo_ensure_schema();
$userId=(string)$user['id'];
$configuration=p50_meta_safe_configuration_status();
$connection=p50mo_connection($userId);
if(!$connection)json_response(['ok'=>true,'connected'=>false,'assets'=>[],'configuration'=>$configuration]);
$assets=array_map(static fn($asset)=>[
    'platform'=>(string)$asset['platform'],'id'=>(string)$asset['asset_id'],'profileId'=>$asset['profile_id']?:null,
    'name'=>(string)$asset['asset_name'],'username'=>(string)$asset['username'],'profileUrl'=>(string)($asset['profile_url']??''),
    'pictureUrl'=>(string)($asset['picture_url']??''),'parentPageId'=>$asset['parent_page_id']?:null,
    'mapped'=>!empty($asset['profile_id']),'lastCheckedAt'=>$asset['last_checked_at']?(string)$asset['last_checked_at'].'Z':null,
    'lastError'=>$asset['last_error']?:null,
],p50mo_assets($userId));
$expires=(string)($connection['token_expires_at']??'');$expiresTs=$expires!==''?strtotime($expires.' UTC'):false;
json_response(['ok'=>true,'connected'=>in_array((string)$connection['status'],['active','reauthorization_required'],true),'status'=>$connection['status'],'account'=>['id'=>$connection['meta_user_id'],'name'=>$connection['meta_user_name']],'scopes'=>preg_split('/\s+/',trim((string)$connection['scopes']))?:[],'tokenExpiresAt'=>$expires!==''?$expires.'Z':null,'tokenExpired'=>$expiresTs===false||$expiresTs<=time(),'requiresReauthorization'=>$connection['status']==='reauthorization_required','assets'=>$assets,'connectedAt'=>(string)$connection['connected_at'].'Z','configuration'=>$configuration]);