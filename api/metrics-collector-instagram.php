<?php
declare(strict_types=1);

function p50_mc_instagram_type(array $media): string {
    $product=strtoupper((string)($media['media_product_type']??''));$type=strtoupper((string)($media['media_type']??''));
    if($product==='STORY')return 'story';if($product==='REELS')return 'reel';
    return match($type){'IMAGE'=>'photo','CAROUSEL_ALBUM'=>'carousel','VIDEO'=>'video',default=>'post'};
}

function p50_mc_instagram(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $username=p50_msc_username('Instagram',$official['normalized_url']);if($username==='')throw new InvalidArgumentException('Lien Instagram officiel non reconnu.');
    $credentials=p50_mc_credentials('Instagram',(string)$official['profile_id']);if(!p50_msc_access_or_status($credentials,$result))return;
    $url='https://graph.facebook.com/v22.0/instagram_business_discovery?'.http_build_query(['username'=>$username,'limit'=>$limit]);
    $response=p50_mc_request($fetch,$url,['Authorization: Bearer '.$credentials['secret']],$result);
    if(!p50_msc_response($response,'Instagram business_discovery',$result))return;$data=(array)(p50_mc_json($response)['data']??[]);
    if(($data['account_type']??'BUSINESS')==='PERSONAL'){$result['status']='unsupported_account_type';return;}
    if(!$data){$result['status']='unavailable_or_blocked';return;}
    $rawHash=hash('sha256',(string)$response['body']);$httpStatus=(int)$response['status'];
    $account=p50_msc_store_account($pdo,$official,'Instagram',['id'=>$data['id']??null,'username'=>$data['username']??$username,'followers'=>$data['followers_count']??null],'instagram_graph_api','business_discovery',$credentials['mode'],$observedAt,$runUuid,$result,[
      'following'=>p50_mc_int($data,'follows_count'),'mediaCount'=>p50_mc_int($data,'media_count')
    ],$httpStatus,$rawHash);
    foreach(array_slice((array)($data['media']??[]),0,$limit) as $media){
        $type=p50_mc_instagram_type($media);if($type==='story'&&empty($media['story_authorized'])){$result['unavailableMetrics']++;continue;}
        $insights=(array)($media['insights']??[]);$item=['id'=>$media['id']??'','url'=>$media['permalink']??'','type'=>$type,'title'=>$media['caption']??'','publishedAt'=>$media['timestamp']??null,'status'=>$media['status']??'active'];
        p50_msc_store_content($pdo,$official,$account,'Instagram',$item,'instagram_graph_api','media',$credentials['mode'],$observedAt,$runUuid,$result,
          ['views'=>p50_mc_int($insights,'views'),'likes'=>p50_mc_int($media,'like_count'),'comments'=>p50_mc_int($media,'comments_count'),'shares'=>p50_mc_int($insights,'shares'),'saves'=>p50_mc_int($insights,'saved')],
          ['reach'=>p50_mc_int($insights,'reach'),'plays'=>p50_mc_int($insights,'plays'),'totalInteractions'=>p50_mc_int($insights,'total_interactions'),'profileActivity'=>p50_mc_int($insights,'profile_activity'),'accountsEngaged'=>p50_mc_int($insights,'accounts_engaged')],$httpStatus,$rawHash);
    }
}
