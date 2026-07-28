<?php
declare(strict_types=1);

function p50_mc_facebook_type(array $post): string {
    $type=strtolower((string)($post['type']??'post'));$format=strtolower((string)($post['format']??''));
    if(in_array($format,['reel','live'],true))return $format;
    return in_array($type,['post','photo','carousel','video','live'],true)?$type:'post';
}

function p50_mc_facebook(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    [$kind,$identity]=p50_msc_facebook_identity($official['normalized_url']);if($identity==='')throw new InvalidArgumentException('Lien Facebook officiel non reconnu.');
    $credentials=p50_mc_credentials('Facebook',(string)$official['profile_id']);if(!p50_msc_access_or_status($credentials,$result))return;
    $response=p50_mc_request($fetch,'https://graph.facebook.com/v22.0/'.rawurlencode($identity).'?'.http_build_query(['fields'=>'id,name,username,followers_count,fan_count,posts','limit'=>$limit]),['Authorization: Bearer '.$credentials['secret']],$result);
    if(!p50_msc_response($response,'Facebook Page',$result))return;$data=(array)(p50_mc_json($response)['data']??[]);
    if(($data['account_type']??'PAGE')!=='PAGE'){$result['status']='unsupported_account_type';return;}
    if(!$data){$result['status']='unavailable_or_blocked';return;}
    $rawHash=hash('sha256',(string)$response['body']);$httpStatus=(int)$response['status'];
    $account=p50_msc_store_account($pdo,$official,'Facebook',['id'=>$data['id']??($kind==='id'?$identity:null),'username'=>$data['username']??$data['name']??null,'followers'=>$data['followers_count']??null],'facebook_graph_api','Page',$credentials['mode'],$observedAt,$runUuid,$result,[
      'fanCount'=>p50_mc_int($data,'fan_count'),'postCount'=>p50_mc_int($data,'post_count'),'videoCount'=>p50_mc_int($data,'video_count')
    ],$httpStatus,$rawHash);
    foreach(array_slice((array)($data['posts']??[]),0,$limit) as $post){
        $metrics=(array)($post['metrics']??[]);$reactions=(array)($post['reactions']??[]);
        $item=['id'=>$post['id']??'','url'=>$post['permalink_url']??'','type'=>p50_mc_facebook_type($post),'title'=>$post['message']??'','publishedAt'=>$post['created_time']??null,'metadata'=>['facebookFormat'=>$post['format']??null]];
        p50_msc_store_content($pdo,$official,$account,'Facebook',$item,'facebook_graph_api','Page posts',$credentials['mode'],$observedAt,$runUuid,$result,
          ['views'=>p50_mc_int($metrics,'views'),'likes'=>p50_mc_int($reactions,'LIKE'),'comments'=>p50_mc_int($metrics,'comments'),'shares'=>p50_mc_int($metrics,'shares')],
          ['reactionsTotal'=>p50_mc_int($reactions,'total'),'likeReactions'=>p50_mc_int($reactions,'LIKE'),'loveReactions'=>p50_mc_int($reactions,'LOVE'),'hahaReactions'=>p50_mc_int($reactions,'HAHA'),'wowReactions'=>p50_mc_int($reactions,'WOW'),'sadReactions'=>p50_mc_int($reactions,'SAD'),'angryReactions'=>p50_mc_int($reactions,'ANGRY'),'videoViews'=>p50_mc_int($metrics,'video_views'),'reach'=>p50_mc_int($metrics,'reach'),'postClicks'=>p50_mc_int($metrics,'post_clicks')],$httpStatus,$rawHash);
    }
}
