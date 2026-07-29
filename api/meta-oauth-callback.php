<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/meta-oauth-core.php';
require __DIR__.'/meta-oauth-errors.php';
set_time_limit(45);

$state=trim((string)($_GET['state']??''));
$nonce=trim((string)($_COOKIE[P50MO_NONCE_COOKIE]??$_COOKIE[P50MO_LEGACY_NONCE_COOKIE]??''));
try{$sessionHash=p50mo_verify_state($state,$nonce);p50mo_clear_nonce();}catch(Throwable $e){p50mo_clear_nonce();error_log('Meta OAuth state: '.$e->getMessage());p50mo_redirect('error','invalid_state');}
if(isset($_GET['error'])){$errorCode=p50mo_dialog_error_code($_GET);p50mo_redirect($errorCode==='access_denied'?'cancelled':'error',$errorCode);}
$code=trim((string)($_GET['code']??''));if($code==='')p50mo_redirect('error','missing_code');

try{
    $stmt=db()->prepare('SELECT u.id FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP() AND u.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$sessionHash]);$sessionUser=$stmt->fetch();if(!is_array($sessionUser))p50mo_redirect('error','pass50_session_expired');$userId=(string)$sessionUser['id'];
    $cfg=p50mo_config();
    $short=p50mo_http(
        'https://graph.facebook.com/'.$cfg['graph_version'].'/oauth/access_token',
        'POST',
        [],
        [
            'grant_type'=>'authorization_code',
            'client_id'=>$cfg['app_id'],
            'client_secret'=>$cfg['app_secret'],
            'redirect_uri'=>$cfg['redirect_uri'],
            'code'=>$code,
        ],
        ['Accept: application/json']
    );
    if($short['status']<200||$short['status']>=300)throw p50mo_error($short,'Échange du code Meta refusé');
    $accessToken=trim((string)($short['json']['access_token']??''));if($accessToken==='')throw new RuntimeException('Jeton d’accès Meta absent.');$expiresIn=max(300,(int)($short['json']['expires_in']??3600));
    $long=p50mo_http('https://graph.facebook.com/'.$cfg['graph_version'].'/oauth/access_token','GET',['grant_type'=>'fb_exchange_token','client_id'=>$cfg['app_id'],'client_secret'=>$cfg['app_secret'],'fb_exchange_token'=>$accessToken]);
    if($long['status']>=200&&$long['status']<300&&trim((string)($long['json']['access_token']??''))!==''){$accessToken=trim((string)$long['json']['access_token']);$expiresIn=max(300,(int)($long['json']['expires_in']??5184000));}
    $me=p50mo_graph('me',$accessToken,['fields'=>'id,name']);if($me['status']<200||$me['status']>=300)throw p50mo_error($me,'Lecture du compte Meta impossible');
    $permissions=p50mo_graph('me/permissions',$accessToken);if($permissions['status']<200||$permissions['status']>=300)throw p50mo_error($permissions,'Lecture des autorisations Meta impossible');
    $granted=[];foreach((array)($permissions['json']['data']??[]) as $permission)if(($permission['status']??'')==='granted')$granted[]=(string)($permission['permission']??'');
    $missing=array_values(array_diff(P50MO_REQUIRED_SCOPES,$granted));if($missing)throw new RuntimeException('Autorisations Meta manquantes : '.implode(', ',$missing).'.');
    $pagesResponse=p50mo_graph('me/accounts',$accessToken,['fields'=>'id,name,access_token,tasks,link,picture{url},instagram_business_account','limit'=>100]);if($pagesResponse['status']<200||$pagesResponse['status']>=300)throw p50mo_error($pagesResponse,'Lecture des Pages Facebook impossible');
    $assets=[];
    foreach((array)($pagesResponse['json']['data']??[]) as $page){
        $pageId=trim((string)($page['id']??''));$pageToken=trim((string)($page['access_token']??''));if($pageId===''||$pageToken==='')continue;
        $detail=p50mo_graph($pageId,$pageToken,['fields'=>'id,name,link,picture{url},instagram_business_account{id,username,name,profile_picture_url}']);$data=$detail['status']>=200&&$detail['status']<300?$detail['json']:$page;
        $pageUrl=trim((string)($data['link']??$page['link']??''));$pageName=(string)($data['name']??$page['name']??'Page Facebook');$picture=(string)($data['picture']['data']['url']??$page['picture']['data']['url']??'');
        $assets[]=['platform'=>'Facebook','asset_id'=>$pageId,'profile_id'=>p50mo_match_profile('Facebook',$pageUrl),'name'=>$pageName,'username'=>'','url'=>$pageUrl,'picture'=>$picture,'parent'=>null,'token'=>$pageToken,'tasks'=>json_encode($page['tasks']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        $ig=$data['instagram_business_account']??null;
        if(is_array($ig)&&trim((string)($ig['id']??''))!==''){$igId=(string)$ig['id'];$igDetail=$ig;if(empty($ig['username'])){$response=p50mo_graph($igId,$pageToken,['fields'=>'id,username,name,profile_picture_url']);if($response['status']>=200&&$response['status']<300)$igDetail=$response['json'];}$username=trim((string)($igDetail['username']??''));$igUrl=$username!==''?'https://www.instagram.com/'.$username.'/':'';$assets[]=['platform'=>'Instagram','asset_id'=>$igId,'profile_id'=>p50mo_match_profile('Instagram',$igUrl,$username),'name'=>(string)($igDetail['name']??$username?:'Compte Instagram'),'username'=>$username,'url'=>$igUrl,'picture'=>(string)($igDetail['profile_picture_url']??''),'parent'=>$pageId,'token'=>$pageToken,'tasks'=>null];}
    }
    p50mo_ensure_schema();$pdo=db();$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO p50_meta_oauth_connections(user_id,meta_user_id,meta_user_name,access_token_encrypted,scopes,token_expires_at,status,last_error,connected_at,last_refreshed_at) VALUES(?,?,?,?,?,?,'active',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE meta_user_id=VALUES(meta_user_id),meta_user_name=VALUES(meta_user_name),access_token_encrypted=VALUES(access_token_encrypted),scopes=VALUES(scopes),token_expires_at=VALUES(token_expires_at),status='active',last_error=NULL,connected_at=UTC_TIMESTAMP(),last_refreshed_at=UTC_TIMESTAMP()")
            ->execute([$userId,(string)($me['json']['id']??''),(string)($me['json']['name']??''),p50mo_encrypt($accessToken),implode(' ',$granted),gmdate('Y-m-d H:i:s',time()+$expiresIn)]);
        $pdo->prepare('DELETE FROM p50_meta_oauth_assets WHERE user_id=?')->execute([$userId]);$insert=$pdo->prepare("INSERT INTO p50_meta_oauth_assets(user_id,platform,asset_id,profile_id,asset_name,username,profile_url,picture_url,parent_page_id,access_token_encrypted,tasks,status,last_checked_at,last_error,connected_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'active',NULL,NULL,UTC_TIMESTAMP())");
        foreach($assets as $asset)$insert->execute([$userId,$asset['platform'],$asset['asset_id'],$asset['profile_id'],$asset['name'],$asset['username'],$asset['url'],$asset['picture'],$asset['parent'],p50mo_encrypt($asset['token']),$asset['tasks']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    p50mo_redirect('connected');
}catch(Throwable $e){$diagnostic=p50mo_exception_error_code($e);error_log('Meta OAuth callback ['.$diagnostic.']: '.$e->getMessage());p50mo_redirect('error',$diagnostic);}
