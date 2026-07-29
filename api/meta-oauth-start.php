<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';
require_method('POST');

$sessionToken=bearer_token();
if(!$sessionToken)json_response(['error'=>'Connexion PASS50 requise.'],401);
try{
    $cfg=p50mo_config();$nonce=p50mo_b64e(random_bytes(24));$state=p50mo_create_state(hash('sha256',$sessionToken),$nonce);p50mo_set_nonce($nonce);
    $params=[
        'client_id'=>$cfg['app_id'],'redirect_uri'=>$cfg['redirect_uri'],'state'=>$state,'response_type'=>'code',
        'scope'=>implode(',',P50MO_REQUIRED_SCOPES),'auth_type'=>'rerequest','display'=>'popup',
    ];
    $url='https://www.facebook.com/'.$cfg['graph_version'].'/dialog/oauth?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    json_response(['ok'=>true,'authorizationUrl'=>$url,'expiresAt'=>gmdate(DATE_ATOM,time()+P50MO_STATE_TTL_SECONDS)]);
}catch(Throwable $e){error_log('Meta OAuth start: '.$e->getMessage());json_response(['error'=>$e->getMessage(),'diagnostic'=>'meta_oauth_configuration'],503);}
