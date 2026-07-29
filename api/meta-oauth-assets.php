<?php
declare(strict_types=1);

function p50mo_page_token_fields(): string {
    // Meta recommande de récupérer d’abord le jeton de Page avec un jeu de champs minimal.
    return 'id,name,access_token,tasks,instagram_business_account';
}

function p50mo_page_detail_fields(): string {
    // Les champs détaillés sont lus seulement après obtention du jeton de Page.
    return 'id,name,tasks,link,picture{url},instagram_business_account{id,username,name,profile_picture_url}';
}

function p50mo_safe_graph_error(array $response): array {
    $error=is_array($response['json']['error']??null)?$response['json']['error']:[];
    $message=trim((string)($error['message']??''));
    if($message!==''){
        $message=preg_replace('/(?:EA[A-Za-z0-9_-]{20,}|access_token=[^&\s]+)/i','[valeur masquée]',$message)??$message;
        $message=substr($message,0,180);
    }
    return [
        'http'=>(int)($response['status']??0),
        'code'=>isset($error['code'])?(int)$error['code']:null,
        'subcode'=>isset($error['error_subcode'])?(int)$error['error_subcode']:null,
        'type'=>substr(trim((string)($error['type']??'')),0,80),
        'message'=>$message,
    ];
}

function p50mo_graph_error_label(array $error): string {
    $parts=[];
    if(!empty($error['code']))$parts[]='code '.(int)$error['code'];
    if(!empty($error['subcode']))$parts[]='sous-code '.(int)$error['subcode'];
    if(!empty($error['message']))$parts[]=(string)$error['message'];
    if(!$parts&&isset($error['http']))$parts[]='HTTP '.(int)$error['http'];
    return implode(' · ',$parts);
}

function p50mo_debug_user_token(string $userToken): array {
    $cfg=p50mo_config();
    $response=p50mo_http(
        'https://graph.facebook.com/'.$cfg['graph_version'].'/debug_token',
        'GET',
        ['input_token'=>$userToken,'access_token'=>$cfg['app_id'].'|'.$cfg['app_secret']]
    );
    if($response['status']<200||$response['status']>=300)return [];
    $data=$response['json']['data']??null;
    if(!is_array($data)||($data['is_valid']??false)!==true)return [];
    if((string)($data['app_id']??'')!==(string)$cfg['app_id'])return [];
    return $data;
}

function p50mo_selected_page_ids(string $userToken): array {
    $debug=p50mo_debug_user_token($userToken);$ids=[];
    foreach((array)($debug['granular_scopes']??[]) as $grant){
        if(!is_array($grant))continue;
        $scope=(string)($grant['scope']??'');
        if(!in_array($scope,['pages_show_list','pages_read_engagement'],true))continue;
        foreach((array)($grant['target_ids']??[]) as $id){
            $id=trim((string)$id);
            if($id!==''&&preg_match('/^[A-Za-z0-9_-]{2,100}$/',$id))$ids[]=$id;
        }
    }
    return array_values(array_unique($ids));
}

function p50mo_discover_page_rows(string $userToken): array {
    $response=p50mo_graph('me/accounts',$userToken,['fields'=>p50mo_page_token_fields(),'limit'=>100]);
    if($response['status']<200||$response['status']>=300)throw p50mo_error($response,'Lecture des Pages Facebook impossible');
    $edgeRows=array_values(array_filter((array)($response['json']['data']??[]),'is_array'));
    $byId=[];
    foreach($edgeRows as $row){$id=trim((string)($row['id']??''));if($id!=='')$byId[$id]=$row;}

    $selectedIds=p50mo_selected_page_ids($userToken);$directErrors=[];
    foreach($selectedIds as $pageId){
        $existing=$byId[$pageId]??[];
        if(is_array($existing)&&trim((string)($existing['access_token']??''))!=='')continue;

        // Appel volontairement minimal, identique au parcours officiel Meta.
        $direct=p50mo_graph($pageId,$userToken,['fields'=>'id,name,access_token']);
        if($direct['status']>=200&&$direct['status']<300&&is_array($direct['json'])&&trim((string)($direct['json']['id']??''))!==''){
            $byId[$pageId]=array_replace(is_array($existing)?$existing:[],$direct['json']);
            if(trim((string)($direct['json']['access_token']??''))===''){
                $directErrors[$pageId]=['http'=>(int)$direct['status'],'code'=>null,'subcode'=>null,'type'=>'missing_page_token','message'=>'Meta a reconnu la Page mais n’a pas fourni de jeton de Page.'];
            }
        }else{
            $directErrors[$pageId]=p50mo_safe_graph_error($direct);
        }
    }
    return ['rows'=>array_values($byId),'edgeCount'=>count($edgeRows),'selectedPageIds'=>$selectedIds,'directErrors'=>$directErrors];
}

function p50mo_assets_from_page(array $page): array {
    $pageId=trim((string)($page['id']??''));$pageToken=trim((string)($page['access_token']??''));
    if($pageId===''||$pageToken==='')return [];
    $detail=p50mo_graph($pageId,$pageToken,['fields'=>p50mo_page_detail_fields()]);
    $data=$detail['status']>=200&&$detail['status']<300&&is_array($detail['json'])?array_replace($page,$detail['json']):$page;
    $pageUrl=trim((string)($data['link']??$page['link']??''));if($pageUrl==='')$pageUrl='https://www.facebook.com/'.$pageId;
    $pageName=trim((string)($data['name']??$page['name']??''));if($pageName==='')$pageName='Page Facebook';
    $picture=(string)($data['picture']['data']['url']??$page['picture']['data']['url']??'');
    $assets=[[
        'platform'=>'Facebook','asset_id'=>$pageId,'profile_id'=>p50mo_match_profile('Facebook',$pageUrl),
        'name'=>$pageName,'username'=>'','url'=>$pageUrl,'picture'=>$picture,'parent'=>null,'token'=>$pageToken,
        'tasks'=>json_encode($data['tasks']??$page['tasks']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]];
    $ig=$data['instagram_business_account']??null;
    if(is_array($ig)&&trim((string)($ig['id']??''))!==''){
        $igId=trim((string)$ig['id']);$igDetail=$ig;
        if(empty($ig['username'])){$igResponse=p50mo_graph($igId,$pageToken,['fields'=>'id,username,name,profile_picture_url']);if($igResponse['status']>=200&&$igResponse['status']<300)$igDetail=$igResponse['json'];}
        $username=trim((string)($igDetail['username']??''));$igUrl=$username!==''?'https://www.instagram.com/'.$username.'/':'';
        $assets[]=[
            'platform'=>'Instagram','asset_id'=>$igId,'profile_id'=>p50mo_match_profile('Instagram',$igUrl,$username),
            'name'=>(string)($igDetail['name']??$username?:'Compte Instagram'),'username'=>$username,'url'=>$igUrl,
            'picture'=>(string)($igDetail['profile_picture_url']??''),'parent'=>$pageId,'token'=>$pageToken,'tasks'=>null,
        ];
    }
    return $assets;
}

function p50mo_discover_authorized_assets(string $userToken): array {
    $discovery=p50mo_discover_page_rows($userToken);$assets=[];$pagesWithoutToken=[];$pagesWithToken=0;
    foreach($discovery['rows'] as $page){
        $pageId=trim((string)($page['id']??''));
        if(trim((string)($page['access_token']??''))===''){$pagesWithoutToken[]=$pageId;continue;}
        $pagesWithToken++;foreach(p50mo_assets_from_page($page) as $asset)$assets[$asset['platform'].'|'.$asset['asset_id']]=$asset;
    }
    $selectedCount=count($discovery['selectedPageIds']);$warning=null;
    $firstError=$discovery['directErrors']?reset($discovery['directErrors']):null;
    $errorLabel=is_array($firstError)?p50mo_graph_error_label($firstError):'';
    if(!$assets){
        if($selectedCount>0&&$pagesWithoutToken){
            $warning='Meta a bien transmis '.count($pagesWithoutToken).' Page(s) sélectionnée(s), mais aucun jeton de Page.';
            if($errorLabel!=='')$warning.=' Réponse Meta : '.$errorLabel.'.';
        }elseif($selectedCount>0){
            $warning='Meta a enregistré '.$selectedCount.' Page(s) dans cette autorisation, mais PASS50 ne peut pas encore les lire.';
            if($errorLabel!=='')$warning.=' Réponse Meta : '.$errorLabel.'.';
        }elseif((int)$discovery['edgeCount']>0){
            $warning='Meta renvoie des Pages, mais sans jeton exploitable.';
        }else{
            $warning='Les autorisations Meta sont accordées, mais aucune Page gérée n’est associée à ce jeton.';
        }
    }elseif($pagesWithoutToken){
        $warning=count($pagesWithoutToken).' Page(s) sélectionnée(s) supplémentaire(s) ont été ignorées car Meta n’a pas fourni leur jeton de Page.';
    }
    return [
        'assets'=>array_values($assets),'warning'=>$warning,'edgePages'=>(int)$discovery['edgeCount'],
        'selectedPages'=>$selectedCount,'pagesWithToken'=>$pagesWithToken,'pagesWithoutToken'=>count($pagesWithoutToken),
        'directLookupErrors'=>count($discovery['directErrors']),
    ];
}

function p50mo_replace_assets_for_user(string $userId,array $assets): void {
    $pdo=db();$pdo->prepare('DELETE FROM p50_meta_oauth_assets WHERE user_id=?')->execute([$userId]);
    $insert=$pdo->prepare("INSERT INTO p50_meta_oauth_assets(user_id,platform,asset_id,profile_id,asset_name,username,profile_url,picture_url,parent_page_id,access_token_encrypted,tasks,status,last_checked_at,last_error,connected_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'active',NULL,NULL,UTC_TIMESTAMP())");
    foreach($assets as $asset)$insert->execute([
        $userId,$asset['platform'],$asset['asset_id'],$asset['profile_id'],$asset['name'],$asset['username'],$asset['url'],$asset['picture'],
        $asset['parent'],p50mo_encrypt((string)$asset['token']),$asset['tasks'],
    ]);
}
