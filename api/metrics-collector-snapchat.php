<?php
declare(strict_types=1);

function p50_mc_snapchat(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $username=p50_msc_username('Snapchat',$official['normalized_url']);if($username==='')throw new InvalidArgumentException('Lien Snapchat officiel non reconnu.');
    $credentials=p50_mc_credentials('Snapchat',(string)$official['profile_id']);if(!p50_msc_access_or_status($credentials,$result))return;
    $response=p50_mc_request($fetch,'https://businessapi.snapchat.com/v1/public_profiles/'.rawurlencode($username).'?limit='.$limit,['Authorization: Bearer '.$credentials['secret']],$result);
    if(!p50_msc_response($response,'Snapchat Public Profile',$result))return;$data=(array)(p50_mc_json($response)['data']??[]);
    if(!$data){$result['status']='unavailable_or_blocked';return;}
    $rawHash=hash('sha256',(string)$response['body']);$httpStatus=(int)$response['status'];
    $account=p50_msc_store_account($pdo,$official,'Snapchat',['id'=>$data['public_profile_id']??$data['id']??null,'username'=>$data['username']??$username,'followers'=>$data['subscriber_count']??null],'snapchat_public_profile_api','Public Profile',$credentials['mode'],$observedAt,$runUuid,$result,[
      'subscriberCount'=>p50_mc_int($data,'subscriber_count'),'spotlightCount'=>p50_mc_int($data,'spotlight_count'),'storyCount'=>p50_mc_int($data,'story_count')
    ],$httpStatus,$rawHash);
    foreach(array_slice((array)($data['contents']??[]),0,$limit) as $content){
        $kind=strtolower((string)($content['type']??''));if($kind==='story'&&empty($content['authorized'])){$result['unavailableMetrics']++;continue;}
        $type=$kind==='spotlight'?'spotlight':($kind==='story'?'story':'unknown');
        $item=['id'=>$content['snap_id']??$content['id']??'','url'=>$content['permalink']??'','type'=>$type,'title'=>$content['title']??'','publishedAt'=>$content['created_at']??null,'status'=>!empty($content['expired'])?'expired':'active'];
        p50_msc_store_content($pdo,$official,$account,'Snapchat',$item,'snapchat_public_profile_api',$type,$credentials['mode'],$observedAt,$runUuid,$result,
          ['views'=>p50_mc_int($content,'views'),'shares'=>p50_mc_int($content,'shares'),'saves'=>p50_mc_int($content,'saves')],[],$httpStatus,$rawHash);
    }
}
