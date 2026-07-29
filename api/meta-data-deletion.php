<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';

require_method('POST');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try{
    $signedRequest=trim((string)($_POST['signed_request']??''));
    if($signedRequest===''){
        $input=json_input();
        $signedRequest=trim((string)($input['signed_request']??''));
    }
    $payload=p50mo_parse_signed_request($signedRequest);
    $metaUserId=(string)$payload['user_id'];
    $confirmationCode=bin2hex(random_bytes(16));
    p50mo_delete_local_data_for_meta_user($metaUserId,$confirmationCode);
    global $config;
    $base=rtrim((string)($config['app']['base_url']??'https://www.pass50.store'),'/');
    if(!filter_var($base,FILTER_VALIDATE_URL))$base='https://www.pass50.store';
    json_response([
        'url'=>$base.'/meta-deletion-status.html?code='.rawurlencode($confirmationCode),
        'confirmation_code'=>$confirmationCode,
    ]);
}catch(Throwable $e){
    error_log('Meta data deletion: '.$e->getMessage());
    json_response(['error'=>'Demande de suppression Meta invalide.'],400);
}
