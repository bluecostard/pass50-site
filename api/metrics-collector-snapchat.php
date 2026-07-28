<?php
declare(strict_types=1);

function p50_mc_snapchat_asset(PDO $pdo,array $official,array $account,array $asset,array $stats,string $kind,string $mode,string $observedAt,string $runUuid,array &$result,int $httpStatus,string $rawHash): void {
    $id=(string)($asset['id']??$asset['snap_id']??$asset['spotlight_id']??$asset['saved_story_id']??$asset['story_id']??'');if($id==='')return;
    $type=$kind==='spotlights'?'spotlight':'story';$status=!empty($asset['expired'])?'expired':'active';
    $item=['id'=>$id,'url'=>$asset['permalink']??$asset['share_url']??'','type'=>$type,'title'=>$asset['title']??'','publishedAt'=>$asset['created_at']??$asset['create_time']??null,'status'=>$status,'metadata'=>['snapAssetType'=>$kind]];
    $views=$stats['VIEWS']??($stats['SPOTLIGHT_VIEWS']??($stats['SAVED_STORY_VIEWS']??($stats['STORY_VIEWS']??null)));
    $shares=$stats['SPOTLIGHT_SHARES']??($stats['SHARES']??null);$saves=$stats['SPOTLIGHT_FAVORITES']??($stats['SAVED_STORY_FAVORITES']??null);
    p50_msc_store_content($pdo,$official,$account,'Snapchat',$item,'snapchat_public_profile_api',$kind.'+stats',$mode,$observedAt,$runUuid,$result,
      ['views'=>p50_mc_int(['value'=>$views],'value'),'shares'=>p50_mc_int(['value'=>$shares],'value'),'saves'=>p50_mc_int(['value'=>$saves],'value')],[],$httpStatus,$rawHash);
}

function p50_mc_snapchat_edge(PDO $pdo,array $official,array $account,string $profileId,string $edge,bool $public,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result,array $credentials): void {
    $headers=['Authorization: Bearer '.$credentials['secret']];$prefix=$public?'/public/v1/':'/v1/';$cursor=null;$collected=0;
    do{
        $query=['limit'=>min(100,$limit-$collected)];if($cursor!==null)$query['cursor']=$cursor;
        $base='https://businessapi.snapchat.com'.$prefix.'public_profiles/'.rawurlencode($profileId).'/'.$edge;
        $edgeResponse=p50_mc_request($fetch,p50_msc_query_url($base,$query),$headers,$result);
        if(!p50_msc_response($edgeResponse,'Snapchat '.$edge,$result,true))return;$payload=p50_mc_json($edgeResponse);
        $assets=p50_msc_snap_assets($payload,$edge);
        foreach($assets as $asset){
            if($collected++>=$limit)break;$asset=(array)($asset[$edge==='spotlights'?'spotlight':($edge==='saved_stories'?'saved_story':'story')]??$asset);
            $id=(string)($asset['id']??$asset['snap_id']??$asset['spotlight_id']??$asset['saved_story_id']??$asset['story_id']??'');$stats=[];
            if($id!==''){
                $statsUrl=$base.'/'.rawurlencode($id).'/stats';
                $statsResponse=p50_mc_request($fetch,$statsUrl,$headers,$result);
                if((int)$statsResponse['status']>=200&&(int)$statsResponse['status']<300)$stats=p50_msc_snap_stats(p50_mc_json($statsResponse));
                else{$result['errors'][]='Snapchat '.$edge.' stats unavailable';$result['status']='partial';}
                $hash=hash('sha256',(string)$edgeResponse['body'].'|'.(string)$statsResponse['body']);
            }else $hash=hash('sha256',(string)$edgeResponse['body']);
            p50_mc_snapchat_asset($pdo,$official,$account,$asset,$stats,$edge,$credentials['mode'],$observedAt,$runUuid,$result,(int)$edgeResponse['status'],$hash);
        }
        $next=$payload['page']['next_page_id']??null;$hasMore=is_string($next)&&$next!==''&&$next!==$cursor;$cursor=$hasMore?$next:null;
    }while($hasMore&&$collected<$limit);
}

function p50_mc_snapchat(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $username=p50_msc_username('Snapchat',$official['normalized_url']);if($username==='')throw new InvalidArgumentException('Lien Snapchat officiel non reconnu.');
    $credentials=p50_mc_credentials('Snapchat',(string)$official['profile_id']);if(!p50_msc_access_or_status($credentials,$result))return;
    $headers=['Authorization: Bearer '.$credentials['secret']];
    $search=p50_mc_request($fetch,p50_msc_query_url('https://businessapi.snapchat.com/public/v1/public_profiles/search',['query'=>$username]),$headers,$result);
    if(!p50_msc_response($search,'Snapchat profile search',$result))return;$matches=(array)(p50_mc_json($search)['public_profiles']??[]);
    $profile=[];
    foreach($matches as $match){$candidate=(array)($match['public_profile']??[]);if(strtolower((string)($candidate['snap_user_name']??$candidate['username']??''))===$username){$profile=$candidate;break;}}
    $profileId=(string)($profile['id']??$profile['profile_id']??'');if($profileId===''){$result['status']='unavailable_or_blocked';return;}
    $profileResponse=p50_mc_request($fetch,'https://businessapi.snapchat.com/public/v1/public_profiles/'.rawurlencode($profileId),$headers,$result);
    if(!p50_msc_response($profileResponse,'Snapchat Public Profile',$result))return;$profilePayload=p50_mc_json($profileResponse);
    $profile=(array)($profilePayload['public_profile']??($profilePayload['public_profiles'][0]['public_profile']??$profile));
    $account=p50_msc_store_account($pdo,$official,'Snapchat',['id'=>$profileId,'username'=>$profile['snap_user_name']??$username,'followers'=>$profile['subscriber_count']??null],
      'snapchat_public_profile_api','public profile',$credentials['mode'],$observedAt,$runUuid,$result,['subscriberCount'=>p50_mc_int($profile,'subscriber_count')],
      (int)$profileResponse['status'],hash('sha256',(string)$profileResponse['body']));
    p50_mc_snapchat_edge($pdo,$official,$account,$profileId,'spotlights',true,$limit,$observedAt,$runUuid,$fetch,$result,$credentials);
    p50_mc_snapchat_edge($pdo,$official,$account,$profileId,'saved_stories',true,$limit,$observedAt,$runUuid,$fetch,$result,$credentials);
    if(!empty($credentials['storiesAuthorized']))p50_mc_snapchat_edge($pdo,$official,$account,$profileId,'stories',false,$limit,$observedAt,$runUuid,$fetch,$result,$credentials);
    else $result['unavailableMetrics']++;
}
