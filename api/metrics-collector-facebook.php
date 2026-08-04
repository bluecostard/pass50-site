<?php
declare(strict_types=1);

function p50_mc_facebook_type(array $post): string {
    $status=strtolower((string)($post['status_type']??''));$attachment=(array)(($post['attachments']['data'][0]??[]));$type=strtolower((string)($attachment['media_type']??$attachment['type']??''));
    if(str_contains($type,'reel'))return 'reel';if(str_contains($type,'live'))return 'live';if(str_contains($type,'video'))return 'video';
    if(str_contains($type,'photo')||$status==='added_photos')return 'photo';if(str_contains($type,'album'))return 'carousel';
    return 'post';
}

function p50_mc_facebook(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $credentials=p50_mc_credentials('Facebook',(string)$official['profile_id']);if(!p50_msc_access_or_status($credentials,$result))return;
    $headers=['Authorization: Bearer '.$credentials['secret']];$pageId=(string)$credentials['pageId'];$graph=p50_msc_graph_root($credentials);
    [$kind,$identity]=p50_msc_facebook_identity($official['normalized_url']);
    if($pageId===''&&$identity==='')throw new InvalidArgumentException('Lien Facebook officiel non reconnu.');
    if($pageId===''&&$kind==='id')$pageId=$identity;
    if($pageId===''){
        $resolve=p50_mc_request($fetch,p50_msc_query_url($graph.rawurlencode($identity),['fields'=>'id']),$headers,$result);
        if(!p50_msc_response($resolve,'Facebook Page resolution',$result))return;$pageId=(string)(p50_mc_json($resolve)['id']??'');
    }
    if($pageId===''){$result['status']='unavailable_or_blocked';return;}
    $pageResponse=p50_mc_request($fetch,p50_msc_query_url($graph.rawurlencode($pageId),['fields'=>'id,name,username,followers_count,fan_count']),$headers,$result);
    if(!p50_msc_response($pageResponse,'Facebook Page',$result))return;$page=p50_mc_json($pageResponse);
    if(($page['account_type']??'PAGE')!=='PAGE'){$result['status']='unsupported_account_type';return;}
    if(!$page){$result['status']='unavailable_or_blocked';return;}
    $account=p50_msc_store_account($pdo,$official,'Facebook',['id'=>$page['id']??$pageId,'username'=>$page['username']??$page['name']??null,'followers'=>$page['followers_count']??null],
      'facebook_graph_api','Page',$credentials['mode'],$observedAt,$runUuid,$result,['fanCount'=>p50_mc_int($page,'fan_count')],(int)$pageResponse['status'],hash('sha256',(string)$pageResponse['body']));

    $fields='id,message,created_time,permalink_url,status_type,attachments{media_type,type,title,description,url,target,media},comments.limit(0).summary(true),shares,like_reactions:reactions.type(LIKE).limit(0).summary(true),reactions.limit(0).summary(true)';
    $after=null;$collected=0;
    do{
        $query=['fields'=>$fields,'limit'=>min(100,$limit-$collected)];if($after!==null)$query['after']=$after;
        $postsResponse=p50_mc_request($fetch,p50_msc_query_url($graph.rawurlencode($pageId).'/posts',$query),$headers,$result);
        if(!p50_msc_response($postsResponse,'Facebook Page posts',$result,true))return;$payload=p50_mc_json($postsResponse);
        foreach((array)($payload['data']??[]) as $post){
            if($collected++>=$limit)break;$post=(array)$post;$insights=[];$insightBody='';
            if(!empty($credentials['insightsAuthorized'])){
                $insightResponse=p50_mc_request($fetch,p50_msc_query_url($graph.rawurlencode((string)$post['id']).'/insights',['metric'=>'post_impressions_unique,post_clicks,post_video_views']),$headers,$result);
                $insightBody=(string)$insightResponse['body'];
                if((int)$insightResponse['status']>=200&&(int)$insightResponse['status']<300)$insights=p50_msc_graph_insights(p50_mc_json($insightResponse));
                else{$result['errors'][]='Facebook post insights unavailable';$result['status']='partial';}
            }else $result['unavailableMetrics']+=3;
            $comments=$post['comments']['summary']['total_count']??null;$shares=$post['shares']['count']??null;$likes=$post['like_reactions']['summary']['total_count']??null;$reactions=$post['reactions']['summary']['total_count']??null;
            $attachment=(array)($post['attachments']['data'][0]??[]);$media=(array)($attachment['media']??[]);$image=(array)($media['image']??[]);$target=(array)($attachment['target']??[]);
            $thumbnail=trim((string)($image['src']??''));
            $postUrl=trim((string)($post['permalink_url']??$target['url']??$attachment['url']??''));
            $message=trim((string)($post['message']??''));
            if($message==='')$message=trim((string)($attachment['title']??$attachment['description']??''));
            $item=['id'=>$post['id']??'','url'=>$postUrl,'type'=>p50_mc_facebook_type($post),'title'=>$message,'publishedAt'=>$post['created_time']??null,
              'metadata'=>['statusType'=>$post['status_type']??null,'thumbnailUrl'=>$thumbnail?:null,'facebookPreviewAvailable'=>$message!==''||$thumbnail!=='']];
            p50_msc_store_content($pdo,$official,$account,'Facebook',$item,'facebook_graph_api','Page posts+insights',$credentials['mode'],$observedAt,$runUuid,$result,
              ['views'=>p50_mc_int($insights,'post_video_views'),'likes'=>p50_mc_int(['value'=>$likes],'value'),'comments'=>p50_mc_int(['value'=>$comments],'value'),'shares'=>p50_mc_int(['value'=>$shares],'value')],
              ['reactionsTotal'=>p50_mc_int(['value'=>$reactions],'value'),'likeReactions'=>p50_mc_int(['value'=>$likes],'value'),'videoViews'=>p50_mc_int($insights,'post_video_views'),'reach'=>p50_mc_int($insights,'post_impressions_unique'),'postClicks'=>p50_mc_int($insights,'post_clicks')],(int)$postsResponse['status'],hash('sha256',(string)$postsResponse['body'].'|'.$insightBody));
        }
        $next=$payload['paging']['cursors']['after']??null;$hasMore=is_string($next)&&$next!==''&&$next!==$after;$after=$hasMore?$next:null;
    }while($hasMore&&$collected<$limit);
}
