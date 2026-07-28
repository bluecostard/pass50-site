<?php
declare(strict_types=1);

function p50_mc_tiktok(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $username=p50_msc_username('TikTok',$official['normalized_url']);if($username==='')throw new InvalidArgumentException('Lien TikTok officiel non reconnu.');
    $credentials=p50_mc_credentials('TikTok',(string)$official['profile_id']);if(!p50_msc_access_or_status($credentials,$result))return;
    if($credentials['mode']==='approved_research'&&!$credentials['approved']){$result['status']='configuration_missing';return;}
    if(!in_array($credentials['mode'],['authorized_display','approved_research'],true)){$result['status']='configuration_missing';return;}
    $research=$credentials['mode']==='approved_research';$endpoint=$research?'research/user/videos':'display/user/videos';
    $url=$research?'https://open.tiktokapis.com/v2/research/video/query/':'https://open.tiktokapis.com/v2/video/list/';
    $response=p50_mc_request($fetch,$url.'?'.http_build_query(['username'=>$username,'max_count'=>$limit]),['Authorization: Bearer '.$credentials['secret']],$result);
    if(!p50_msc_response($response,'TikTok '.$endpoint,$result))return;$payload=p50_mc_json($response);$user=(array)($payload['data']['user']??[]);
    if(!$user){$result['status']='unavailable_or_blocked';return;}
    $normalized=['id'=>$user['open_id']??$user['id']??null,'username'=>$user['username']??$username,'followers'=>$user['follower_count']??null];
    $rawHash=hash('sha256',(string)$response['body']);$httpStatus=(int)$response['status'];
    $account=p50_msc_store_account($pdo,$official,'TikTok',$normalized,'tiktok_official_api',$endpoint,$credentials['mode'],$observedAt,$runUuid,$result,[
      'following'=>p50_mc_int($user,'following_count'),'totalLikes'=>p50_mc_int($user,'likes_count'),'videoCount'=>p50_mc_int($user,'video_count')
    ],$httpStatus,$rawHash);
    foreach(array_slice((array)($payload['data']['videos']??[]),0,$limit) as $video){
        if(($video['status']??'active')==='deleted')continue;$type=($video['type']??'video')==='photo'?'photo':'video';
        $item=['id'=>$video['id']??'','url'=>$video['share_url']??'','type'=>$type,'title'=>$video['title']??$video['description']??'','publishedAt'=>p50_msc_time($video['create_time']??null),'metadata'=>['duration'=>p50_mc_int($video,'duration')]];
        p50_msc_store_content($pdo,$official,$account,'TikTok',$item,'tiktok_official_api',$endpoint,$credentials['mode'],$observedAt,$runUuid,$result,
          ['views'=>p50_mc_int($video,'view_count'),'likes'=>p50_mc_int($video,'like_count'),'comments'=>p50_mc_int($video,'comment_count'),'shares'=>p50_mc_int($video,'share_count'),'saves'=>p50_mc_int($video,'favorite_count')],[],$httpStatus,$rawHash);
    }
}
