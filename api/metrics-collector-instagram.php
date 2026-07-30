<?php
declare(strict_types=1);

function p50_mc_instagram_type(array $media): string {
    $product=strtoupper((string)($media['media_product_type']??''));$type=strtoupper((string)($media['media_type']??''));
    if($product==='STORY')return 'story';if($product==='REELS')return 'reel';
    return match($type){'IMAGE'=>'photo','CAROUSEL_ALBUM'=>'carousel','VIDEO'=>'video',default=>'post'};
}

function p50_mc_instagram_insight_groups(string $type): array {
    return match($type){
      'story'=>[['impressions','reach','replies','shares','total_interactions'],['reach','replies']],
      'reel','video'=>[['views','plays','reach','shares','saved','total_interactions','accounts_engaged'],['views','reach']],
      default=>[['reach','shares','saved','total_interactions','accounts_engaged'],['reach']],
    };
}

function p50_mc_instagram_insights(callable $fetch,array $headers,string $graph,string $mediaId,string $type,array &$result): array {
    [$preferred,$fallback]=p50_mc_instagram_insight_groups($type);$bodies=[];
    foreach([$preferred,$fallback] as $index=>$metrics){
        $response=p50_mc_request($fetch,p50_msc_query_url($graph.rawurlencode($mediaId).'/insights',['metric'=>implode(',',$metrics)]),$headers,$result);
        $bodies[]=(string)($response['body']??'');
        if((int)$response['status']>=200&&(int)$response['status']<300)return [p50_msc_graph_insights(p50_mc_json($response)),hash('sha256',implode('|',$bodies))];
        if($index===0&&$fallback===$preferred)break;
    }
    $result['errors'][]='Instagram media insights unavailable';$result['status']='partial';
    return [[],hash('sha256',implode('|',$bodies))];
}

function p50_mc_instagram_media(PDO $pdo,array $official,array $account,array $media,array $insights,string $mode,string $observedAt,string $runUuid,array &$result,int $httpStatus,string $rawHash): void {
    $type=p50_mc_instagram_type($media);if($type==='story'&&empty($media['story_authorized'])){$result['unavailableMetrics']++;return;}
    $item=['id'=>$media['id']??'','url'=>$media['permalink']??'','type'=>$type,'title'=>$media['caption']??'','publishedAt'=>$media['timestamp']??null,'status'=>$media['status']??'active'];
    p50_msc_store_content($pdo,$official,$account,'Instagram',$item,'instagram_graph_api','media+insights',$mode,$observedAt,$runUuid,$result,
      ['views'=>p50_mc_int($insights,'views'),'likes'=>p50_mc_int($media,'like_count'),'comments'=>p50_mc_int($media,'comments_count'),'shares'=>p50_mc_int($insights,'shares'),'saves'=>p50_mc_int($insights,'saved')],
      ['reach'=>p50_mc_int($insights,'reach'),'plays'=>p50_mc_int($insights,'plays'),'totalInteractions'=>p50_mc_int($insights,'total_interactions'),'profileActivity'=>p50_mc_int($insights,'profile_activity'),'accountsEngaged'=>p50_mc_int($insights,'accounts_engaged')],$httpStatus,$rawHash);
}

function p50_mc_instagram_edge(PDO $pdo,array $official,array $account,string $accountId,string $edge,int $limit,string $mode,string $observedAt,string $runUuid,callable $fetch,array &$result,array $headers,string $graph,bool $insightsAuthorized=true): int {
    $after=null;$collected=0;$mediaFields='id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count';
    do{
        $query=['fields'=>$mediaFields,'limit'=>min(100,$limit-$collected)];if($after!==null)$query['after']=$after;
        $mediaResponse=p50_mc_request($fetch,p50_msc_query_url($graph.rawurlencode($accountId).'/'.$edge,$query),$headers,$result);
        if(!p50_msc_response($mediaResponse,'Instagram '.$edge,$result,true))return $collected;$mediaPayload=p50_mc_json($mediaResponse);
        foreach((array)($mediaPayload['data']??[]) as $media){
            if($collected++>=$limit)break;$media=(array)$media;
            if($edge==='stories'){$media['media_product_type']='STORY';$media['story_authorized']=true;}
            $type=p50_mc_instagram_type($media);$insights=[];$insightHash=hash('sha256','insights_not_authorized');
            if($insightsAuthorized)[$insights,$insightHash]=p50_mc_instagram_insights($fetch,$headers,$graph,(string)$media['id'],$type,$result);
            else $result['unavailableMetrics']+=5;
            p50_mc_instagram_media($pdo,$official,$account,$media,$insights,$mode,$observedAt,$runUuid,$result,(int)$mediaResponse['status'],hash('sha256',(string)$mediaResponse['body'].'|'.$insightHash));
        }
        $next=$mediaPayload['paging']['cursors']['after']??null;$hasMore=is_string($next)&&$next!==''&&$next!==$after;$after=$hasMore?$next:null;
    }while($hasMore&&$collected<$limit);
    return $collected;
}

function p50_mc_instagram(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $username=p50_msc_username('Instagram',$official['normalized_url']);if($username==='')throw new InvalidArgumentException('Lien Instagram officiel non reconnu.');
    $credentials=p50_mc_credentials('Instagram',(string)$official['profile_id']);if(!p50_msc_access_or_status($credentials,$result))return;
    $headers=['Authorization: Bearer '.$credentials['secret']];$accountId=(string)$credentials['accountId'];$discoveryId=(string)$credentials['discoveryAccountId'];$graph=p50_msc_graph_root($credentials);
    $fields='id,username,account_type,followers_count,follows_count,media_count';
    $prefetchedMedia=[];
    if($credentials['mode']==='business_discovery'){
        if($discoveryId===''){$result['status']='configuration_missing';return;}
        $mediaFields='id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count';
        $discovery='business_discovery.username('.$username.'){'.$fields.',media.limit('.min(100,$limit).'){'.$mediaFields.'}}';
        $response=p50_mc_request($fetch,p50_msc_query_url($graph.rawurlencode($discoveryId),['fields'=>$discovery]),$headers,$result);
        if(!p50_msc_response($response,'Instagram business_discovery',$result))return;
        $root=p50_mc_json($response);$data=(array)($root['business_discovery']??[]);
        $prefetchedMedia=(array)($data['media']['data']??[]);
    }else{
        if($accountId===''){$result['status']='configuration_missing';return;}
        $response=p50_mc_request($fetch,p50_msc_query_url($graph.rawurlencode($accountId),['fields'=>$fields]),$headers,$result);
        if(!p50_msc_response($response,'Instagram account',$result))return;$data=p50_mc_json($response);
    }
    if(!$data){$result['status']='unavailable_or_blocked';return;}
    if(isset($data['account_type'])&&!in_array(strtoupper((string)$data['account_type']),['BUSINESS','MEDIA_CREATOR','CREATOR'],true)){$result['status']='unsupported_account_type';return;}
    $account=p50_msc_store_account($pdo,$official,'Instagram',['id'=>$data['id']??$accountId,'username'=>$data['username']??$username,'followers'=>$data['followers_count']??null],
      'instagram_graph_api',$credentials['mode']==='business_discovery'?'business_discovery':'account',$credentials['mode'],$observedAt,$runUuid,$result,
      ['following'=>p50_mc_int($data,'follows_count'),'mediaCount'=>p50_mc_int($data,'media_count')],(int)$response['status'],hash('sha256',(string)$response['body']));
    if($credentials['mode']==='business_discovery'){
        foreach(array_slice($prefetchedMedia,0,$limit) as $media)p50_mc_instagram_media($pdo,$official,$account,(array)$media,[],$credentials['mode'],$observedAt,$runUuid,$result,(int)$response['status'],hash('sha256',(string)$response['body']));
        return;
    }
    $storyCount=0;
    if(!empty($credentials['storiesAuthorized']))$storyCount=p50_mc_instagram_edge($pdo,$official,$account,$accountId,'stories',$limit,$credentials['mode'],$observedAt,$runUuid,$fetch,$result,$headers,$graph,!empty($credentials['insightsAuthorized']));
    else $result['unavailableMetrics']++;
    if($storyCount<$limit)p50_mc_instagram_edge($pdo,$official,$account,$accountId,'media',$limit-$storyCount,$credentials['mode'],$observedAt,$runUuid,$fetch,$result,$headers,$graph,!empty($credentials['insightsAuthorized']));
}
