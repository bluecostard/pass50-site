<?php
declare(strict_types=1);

function p50mo_dialog_error_code(array $query): string {
    $error=strtolower(trim((string)($query['error']??'')));
    $reason=strtolower(trim((string)($query['error_reason']??'')));
    $description=strtolower(trim((string)($query['error_description']??'')));
    $combined=$error.' '.$reason.' '.$description;
    if(str_contains($combined,'access_denied')||str_contains($combined,'user_denied'))return 'access_denied';
    if(str_contains($combined,'public_profile')||str_contains($combined,'advanced access'))return 'public_profile_advanced_access';
    if(str_contains($combined,'config_id')||str_contains($combined,'configuration'))return 'invalid_configuration';
    if(str_contains($combined,'redirect_uri')||str_contains($combined,'redirect uri'))return 'redirect_uri_mismatch';
    if(str_contains($combined,'invalid_scope')||str_contains($combined,'invalid scopes')||str_contains($combined,'supported permission'))return 'unsupported_permission';
    if(str_contains($combined,'app not setup')||str_contains($combined,'app isn\'t available')||str_contains($combined,'application unavailable'))return 'app_not_available';
    return $error!==''?preg_replace('/[^a-z0-9_-]+/','_',substr($error,0,64)):'meta_dialog_error';
}

function p50mo_exception_error_code(Throwable $error): string {
    $message=strtolower($error->getMessage());
    if(str_contains($message,'public_profile')||str_contains($message,'advanced access'))return 'public_profile_advanced_access';
    if(str_contains($message,'configuration')||str_contains($message,'config_id'))return 'invalid_configuration';
    if(str_contains($message,'redirect_uri')||str_contains($message,'redirect uri')||str_contains($message,'url de redirection'))return 'redirect_uri_mismatch';
    if(
        str_contains($message,'invalid client')||
        str_contains($message,'app secret')||
        str_contains($message,'client_secret')||
        str_contains($message,'client secret')||
        str_contains($message,'secret de l’application')||
        str_contains($message,"secret de l'application")||
        str_contains($message,'secret fourni')||
        str_contains($message,'credentials')
    )return 'invalid_client';
    if(
        str_contains($message,'authorization code')||
        str_contains($message,'verification code')||
        str_contains($message,'code has been used')||
        str_contains($message,'code was already used')||
        str_contains($message,'invalid code')||
        str_contains($message,'code expir')
    )return 'code_exchange_failed';
    if(str_contains($message,'autorisations meta manquantes')||str_contains($message,'permissions'))return 'permissions_missing';
    if(str_contains($message,'session'))return 'pass50_session_expired';
    if(str_contains($message,'échange du code')||str_contains($message,'code meta'))return 'code_exchange_failed';
    if(str_contains($message,'pages facebook'))return 'pages_access_failed';
    return 'connection_failed';
}
