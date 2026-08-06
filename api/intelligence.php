<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/intelligence-signals-core.php';
$user=auth_user();
require_role($user,'owner','admin');

if($_SERVER['REQUEST_METHOD']==='GET')json_response(p50_is_dashboard());
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
