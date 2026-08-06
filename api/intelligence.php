<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/intelligence-signals-live-refresh.php';
$user=auth_user();
require_role($user,'owner','admin');

if($_SERVER['REQUEST_METHOD']==='GET'){
    $liveRefresh=['version'=>P50_INTELLIGENCE_SIGNALS_LIVE_REFRESH,'processed'=>0,'errors'=>[]];
    try{$liveRefresh=p50_is_live_refresh(20,60);}
    catch(Throwable $error){
        $liveRefresh['errors'][]=['error'=>substr($error->getMessage(),0,180)];
        error_log('PASS50 Intelligence & Signaux live refresh non bloquant: '.$error->getMessage());
    }
    json_response(['liveRefresh'=>$liveRefresh]+p50_is_dashboard());
}
require_method('POST');
$input=json_input();$action=trim((string)($input['action']??''));
if(in_array($action,['validate','reject'],true)){
    $signalId=(int)($input['signalId']??0);
    if($signalId<=0)json_response(['error'=>'Signal invalide.'],422);
    $updated=p50_is_review_signal($signalId,$action==='validate'?'validated':'rejected',$user['id']??null);
    if(!$updated)json_response(['error'=>'Signal introuvable ou déjà traité.'],404);
    json_response(p50_is_dashboard());
}
if($action==='create'){
    try{$signalId=p50_is_create_manual_signal($input,$user['id']??null);}
    catch(InvalidArgumentException $e){json_response(['error'=>$e->getMessage()],422);}
    json_response(['createdSignalId'=>$signalId]+p50_is_dashboard());
}
json_response(['error'=>'Action inconnue.'],422);
