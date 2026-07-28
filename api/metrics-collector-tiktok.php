<?php
declare(strict_types=1);

function p50_mc_tiktok_video(PDO $pdo,array $official,array $account,array $video,string $source,string $endpoint,string $mode,string $observedAt,string $runUuid,array &$result,int $httpStatus,string $rawHash): void {
    if(($video['status']??'active')==='deleted')return;
    $type=strtolower((string)($video['type']??''))==='photo'?'photo':'video';
    $item=['id'=>$video['id']??'','url'=>$video['share_url']??$video['embed_link']??'','type'=>$type,'title'=>$video['title']??$video['video_description']??$video['description']??'',
      'publishedAt'=>p50_msc_time($video['create_time']??null),'metadata'=>['duration'=>p50_mc_int($video,isset($video['video_duration'])?'video_duration':'duration')]];
    p50_msc_store_content($pdo,$official,$account,'TikTok',$item,$source,$endpoint,$mode,$observedAt,$runUuid,$result,
      ['views'=>p50_mc_int($video,'view_count'),'likes'=>p50_mc_int($video,'like_count'),'comments'=>p50_mc_int($video,'comment_count'),'shares'=>p50_mc_int($video,'share_count'),'saves'=>p50_mc_int($video,'favorites_count')],[],$httpStatus,$rawHash);
}

function p50_mc_tiktok_display(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result,array $credentials): void {
    $headers=['Authorization: Bearer '.$credentials['secret']];
    $userFields='open_id,union_id,avatar_url,display_name,bio_description,profile_deep_link,is_verified,follower_count,following_count,likes_count,video_count';
    $userResponse=p50_mc_request($fetch,p50_msc_query_url('https://open.tiktokapis.com/v2/user/info/',['fields'=>$userFields]),$headers,$result);
    if(!p50_msc_response($userResponse,'TikTok user/info',$result))return;
    $user=(array)(p50_mc_json($userResponse)['data']['user']??[]);
    if(!$user){$result['status']='unavailable_or_blocked';return;}
    $userHash=hash('sha256',(string)$userResponse['body']);
    $account=p50_msc_store_account($pdo,$official,'TikTok',['id'=>$user['open_id']??null,'username'=>p50_msc_username('TikTok',$official['normalized_url']),'followers'=>$user['follower_count']??null],
      'tiktok_display_api','user/info',$credentials['mode'],$observedAt,$runUuid,$result,
      ['following'=>p50_mc_int($user,'following_count'),'totalLikes'=>p50_mc_int($user,'likes_count'),'videoCount'=>p50_mc_int($user,'video_count')],(int)$userResponse['status'],$userHash);

    $fields='id,title,video_description,duration,cover_image_url,embed_link,share_url,create_time,view_count,like_count,comment_count,share_count';
    $cursor=0;$collected=0;
    do{
        $count=min(20,$limit-$collected);if($count<=0)break;
        $body=['max_count'=>$count];if($cursor>0)$body['cursor']=$cursor;
        $videosResponse=p50_mc_request($fetch,p50_msc_query_url('https://open.tiktokapis.com/v2/video/list/',['fields'=>$fields]),$headers,$result,'POST',$body);
        if(!p50_msc_response($videosResponse,'TikTok video/list',$result,true))return;
        $payload=p50_mc_json($videosResponse);$data=(array)($payload['data']??[]);$videos=(array)($data['videos']??[]);
        $rawHash=hash('sha256',(string)$videosResponse['body']);
        foreach($videos as $video){if($collected++>=$limit)break;p50_mc_tiktok_video($pdo,$official,$account,(array)$video,'tiktok_display_api','video/list',$credentials['mode'],$observedAt,$runUuid,$result,(int)$videosResponse['status'],$rawHash);}
        $next=(int)($data['cursor']??0);$hasMore=!empty($data['has_more'])&&$next!==$cursor;$cursor=$next;
    }while($hasMore&&$collected<$limit);
}

function p50_mc_tiktok_research(PDO $pdo,array $official,string $username,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result,array $credentials): void {
    $headers=['Authorization: Bearer '.$credentials['secret']];
    $userFields='username,display_name,bio_description,avatar_url,is_verified,follower_count,following_count,likes_count,video_count,bio_url';
    $userResponse=p50_mc_request($fetch,p50_msc_query_url('https://open.tiktokapis.com/v2/research/user/info/',['fields'=>$userFields]),$headers,$result,'POST',['username'=>$username]);
    if(!p50_msc_response($userResponse,'TikTok research user/info',$result))return;
    $user=(array)(p50_mc_json($userResponse)['data']??[]);
    if(!$user){$result['status']='unavailable_or_blocked';return;}
    $account=p50_msc_store_account($pdo,$official,'TikTok',['id'=>null,'username'=>$user['username']??$username,'followers'=>$user['follower_count']??null],
      'tiktok_research_api','research/user/info',$credentials['mode'],$observedAt,$runUuid,$result,
      ['following'=>p50_mc_int($user,'following_count'),'totalLikes'=>p50_mc_int($user,'likes_count'),'videoCount'=>p50_mc_int($user,'video_count')],(int)$userResponse['status'],hash('sha256',(string)$userResponse['body']));

    $end=(new DateTimeImmutable($observedAt,new DateTimeZone('UTC')))->format('Ymd');$start=(new DateTimeImmutable($observedAt,new DateTimeZone('UTC')))->modify('-30 days')->format('Ymd');
    $fields='id,username,video_description,create_time,share_count,view_count,like_count,comment_count,favorites_count,video_duration';
    $cursor=0;$searchId=null;$collected=0;
    do{
        $body=['query'=>['and'=>[['operation'=>'EQ','field_name'=>'username','field_values'=>[$username]]]],'start_date'=>$start,'end_date'=>$end,'max_count'=>min(100,$limit-$collected),'cursor'=>$cursor,'is_random'=>false];
        if($searchId!==null)$body['search_id']=$searchId;
        $videosResponse=p50_mc_request($fetch,p50_msc_query_url('https://open.tiktokapis.com/v2/research/video/query/',['fields'=>$fields]),$headers,$result,'POST',$body);
        if(!p50_msc_response($videosResponse,'TikTok research video/query',$result,true))return;
        $data=(array)(p50_mc_json($videosResponse)['data']??[]);$rawHash=hash('sha256',(string)$videosResponse['body']);
        foreach((array)($data['videos']??[]) as $video){if($collected++>=$limit)break;p50_mc_tiktok_video($pdo,$official,$account,(array)$video,'tiktok_research_api','research/video/query',$credentials['mode'],$observedAt,$runUuid,$result,(int)$videosResponse['status'],$rawHash);}
        $next=(int)($data['cursor']??0);$nextSearch=isset($data['search_id'])?(string)$data['search_id']:$searchId;$hasMore=!empty($data['has_more'])&&($next!==$cursor||$nextSearch!==$searchId);$cursor=$next;$searchId=$nextSearch;
    }while($hasMore&&$collected<$limit);
}

function p50_mc_tiktok(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $username=p50_msc_username('TikTok',$official['normalized_url']);if($username==='')throw new InvalidArgumentException('Lien TikTok officiel non reconnu.');
    $credentials=p50_mc_credentials('TikTok',(string)$official['profile_id']);if(!p50_msc_access_or_status($credentials,$result))return;
    if($credentials['mode']==='authorized_display'){p50_mc_tiktok_display($pdo,$official,$limit,$observedAt,$runUuid,$fetch,$result,$credentials);return;}
    if($credentials['mode']==='approved_research'&&!empty($credentials['approved'])){p50_mc_tiktok_research($pdo,$official,$username,$limit,$observedAt,$runUuid,$fetch,$result,$credentials);return;}
    $result['status']='configuration_missing';
}
