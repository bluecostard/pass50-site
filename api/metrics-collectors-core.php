<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-schema-core.php';

const P50_METRICS_COLLECTOR_VERSION='1.0.0';
const P50_METRICS_COLLECTOR_PROFILES_MAX=10;
const P50_METRICS_COLLECTOR_CONTENTS_MAX=10;

function p50_mc_platform(string $value): string {
    return match(strtolower(trim($value))){'youtube'=>'YouTube','x','twitter'=>'X','tiktok'=>'TikTok','instagram'=>'Instagram','facebook'=>'Facebook','snapchat'=>'Snapchat',default=>''};
}

function p50_mc_config(string $platform): string {
    global $config;
    return $platform==='YouTube'
        ?trim((string)($config['metrics']['PASS50_YOUTUBE_API_KEY']??''))
        :trim((string)($config['metrics']['x_bearer_token']??(defined('PASS50_X_BEARER_TOKEN')?PASS50_X_BEARER_TOKEN:(getenv('PASS50_X_BEARER_TOKEN')?:''))));
}

function p50_mc_http(string $url,array $headers=[],string $method='GET',?array $jsonBody=null): array {
    $ch=curl_init($url);if($ch===false)throw new RuntimeException('HTTP indisponible.');
    $method=strtoupper($method);if(!in_array($method,['GET','POST'],true))throw new InvalidArgumentException('Méthode HTTP non autorisée.');
    if($jsonBody!==null){$headers[]='Content-Type: application/json';$headers[]='Accept: application/json';}
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'PASS50-Metrics-Collectors/'.P50_METRICS_COLLECTOR_VERSION,CURLOPT_HTTPHEADER=>array_values(array_unique($headers)),CURLOPT_CUSTOMREQUEST=>$method];
    if($jsonBody!==null)$options[CURLOPT_POSTFIELDS]=p50_metrics_json($jsonBody);
    curl_setopt_array($ch,$options);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);$error=curl_error($ch);curl_close($ch);
    return ['status'=>$status,'body'=>$body===false?'':(string)$body,'url'=>$effective?:$url,'error'=>$error];
}

function p50_mc_json(array $response): array {
    $value=json_decode((string)($response['body']??''),true);
    return is_array($value)?$value:[];
}

function p50_mc_int(array $source,string $key): int|string|null {
    if(!array_key_exists($key,$source))return null;
    $value=$source[$key];
    if($value===null)return null;
    if(is_int($value))return $value;
    if(is_string($value)&&preg_match('/^\d+$/',$value))return $value;
    return is_scalar($value)?'invalid:'.gettype($value):'invalid:type';
}

function p50_mc_future_metrics(array $values): array {
    $invalid=false;
    foreach($values as $key=>$value)if(is_string($value)&&str_starts_with($value,'invalid:')){$values[$key]=null;$invalid=true;}
    return [$values,$invalid];
}

function p50_mc_official(PDO $pdo,string $profileId,string $platform): array {
    global $config;$threshold=max(90,min(100,(int)($config['data_engine']['confidence_threshold']??90)));
    $stmt=$pdo->prepare("SELECT r.profile_id,r.public_name,s.normalized_url,s.confidence
      FROM p50_profile_registry r JOIN p50_social_links s ON s.profile_id=r.profile_id
      WHERE r.profile_id=? AND r.alive=1 AND s.platform=? AND s.status='verified' AND s.confidence>=? LIMIT 1");
    $stmt->execute([$profileId,$platform,$threshold]);$row=$stmt->fetch();
    if(!$row)throw new InvalidArgumentException('Profil actif ou lien officiel vérifié introuvable.');
    return $row;
}

function p50_mc_provenance(string $platform,string $sourceType,string $endpoint,array $official,string $fetchedAt,int $httpStatus,string $runUuid,string $mode): array {
    return ['collectorVersion'=>P50_METRICS_COLLECTOR_VERSION,'platform'=>$platform,'sourceType'=>$sourceType,'endpoint'=>$endpoint,'endpointName'=>$endpoint,'officialLink'=>$official['normalized_url'],'profileId'=>$official['profile_id'],'fetchedAt'=>$fetchedAt,'httpStatus'=>$httpStatus,'accessMode'=>$mode,'runUuid'=>$runUuid];
}

function p50_mc_result(string $platform,string $profileId,string $startedAt,string $runUuid): array {
    return ['platform'=>$platform,'profileId'=>$profileId,'accountFound'=>false,'contentsFound'=>0,'capturesRecorded'=>0,'duplicatesSkipped'=>0,'quarantined'=>0,'unavailableMetrics'=>0,'requestsAttempted'=>0,'requestsSucceeded'=>0,'rateLimited'=>false,'status'=>'running','errors'=>[],'startedAt'=>$startedAt,'finishedAt'=>null,'runUuid'=>$runUuid];
}

function p50_mc_capture(PDO $pdo,array &$result,array $input): void {
    if(isset($input['contentId'])&&array_key_exists('views',$input)&&is_numeric($input['views'])){
        $stmt=$pdo->prepare("SELECT views FROM p50_metric_captures WHERE account_id=? AND content_id=? AND quality_status='usable' AND views IS NOT NULL ORDER BY observed_at DESC,id DESC LIMIT 1");
        $stmt->execute([(int)$input['accountId'],(int)$input['contentId']]);$previous=$stmt->fetchColumn();
        if($previous!==false&&(int)$input['views']<(int)$previous){
            $observed=(int)$input['views'];$input['views']='decrease:'.(string)$observed;
            $input['metadata']=array_replace((array)($input['metadata']??[]),['metricAnomaly'=>['previousValue'=>(int)$previous,'observedValue'=>$observed,'metricName'=>'views','reason'=>'cumulative_counter_decrease','collectorDecision'=>'quarantined']]);
        }
    }
    $capture=p50_metrics_record_capture($pdo,$input);
    $result['capturesRecorded']+=(int)$capture['created'];
    $result['duplicatesSkipped']+=(int)$capture['duplicate'];
    $result['quarantined']+=(int)$capture['quarantined'];
}

function p50_mc_request(callable $fetch,string $url,array $headers,array &$result,string $method='GET',?array $jsonBody=null): array {
    if($jsonBody!==null){$headers[]='Content-Type: application/json';$headers[]='Accept: application/json';}
    $result['requestsAttempted']++;$response=$fetch($url,array_values(array_unique($headers)),strtoupper($method),$jsonBody);
    $status=(int)($response['status']??0);
    if($status>=200&&$status<300)$result['requestsSucceeded']++;
    if($status===429)$result['rateLimited']=true;
    return $response;
}

function p50_mc_youtube_identifier(string $url): array {
    $path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
    if(preg_match('#^channel/(UC[A-Za-z0-9_-]+)$#',$path,$m))return ['id',$m[1]];
    if(preg_match('#^@([A-Za-z0-9._-]+)$#',$path,$m))return ['handle',$m[1]];
    if(preg_match('#^(?:user|c)/([A-Za-z0-9._-]+)$#',$path,$m))return ['legacy',$m[1]];
    return ['',''];
}

function p50_mc_youtube_duration_seconds(?string $duration): ?int {
    if(!preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/',(string)$duration,$match))return null;
    return ((int)($match[1]??0)*3600)+((int)($match[2]??0)*60)+(int)($match[3]??0);
}

function p50_mc_youtube_content_type(array $video,?string $demonstratedUrl=null): array {
    $snippet=(array)($video['snippet']??[]);$details=(array)($video['contentDetails']??[]);$live=(array)($video['liveStreamingDetails']??[]);
    if(($snippet['liveBroadcastContent']??'')==='live'||(!empty($live['actualStartTime'])&&empty($live['actualEndTime'])))return ['live','live',false];
    if(!empty($live['actualEndTime']))return ['video','replay',false];
    if($demonstratedUrl!==null&&preg_match('#^https?://(?:www\.)?youtube\.com/shorts/[A-Za-z0-9_-]+#i',$demonstratedUrl))return ['short','short',false];
    $seconds=p50_mc_youtube_duration_seconds(isset($details['duration'])?(string)$details['duration']:null);
    return ['video','video',$seconds!==null&&$seconds<=180];
}

function p50_mc_youtube(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    [$kind,$identifier]=p50_mc_youtube_identifier($official['normalized_url']);
    if($identifier==='')throw new InvalidArgumentException('Lien YouTube officiel non reconnu.');
    $key=p50_mc_config('YouTube');
    if($key===''){p50_mc_youtube_fallback($pdo,$official,$kind,$identifier,$limit,$observedAt,$runUuid,$fetch,$result);return;}
    $params=['part'=>'snippet,statistics,contentDetails','key'=>$key];
    $params[$kind==='id'?'id':($kind==='handle'?'forHandle':'forUsername')]=$identifier;
    $response=p50_mc_request($fetch,'https://www.googleapis.com/youtube/v3/channels?'.http_build_query($params),[],$result);
    $status=(int)$response['status'];$payload=p50_mc_json($response);
    if($status===403&&str_contains(strtolower((string)$response['body']),'quota'))throw new RuntimeException('YouTube quota_exceeded');
    if($status===403)throw new RuntimeException('YouTube forbidden');
    if($status===429)throw new RuntimeException('YouTube rate_limited');
    $channel=$payload['items'][0]??null;if(!is_array($channel))throw new RuntimeException('Chaîne YouTube introuvable.');
    $fetched=gmdate('c');$channelId=(string)$channel['id'];$stats=(array)($channel['statistics']??[]);
    $prov=p50_mc_provenance('YouTube','youtube_api','channels.list',$official,$fetched,$status,$runUuid,'api');
    $account=p50_metrics_upsert_account($pdo,['profileId'=>$official['profile_id'],'platform'=>'YouTube','platformAccountId'=>$channelId,'handle'=>$kind==='handle'?$identifier:null,'canonicalUrl'=>$official['normalized_url'],'confidence'=>$official['confidence'],'sourceType'=>'youtube_api','observedAt'=>$observedAt,'provenance'=>$prov]);
    $result['accountFound']=true;
    $followers=!empty($channel['statistics']['hiddenSubscriberCount'])?null:p50_mc_int($stats,'subscriberCount');
    [$future,$invalidFuture]=p50_mc_future_metrics(['totalViews'=>p50_mc_int($stats,'viewCount'),'videoCount'=>p50_mc_int($stats,'videoCount')]);
    if($followers===null)$result['unavailableMetrics']++;
    if($followers!==null||$future['totalViews']!==null||$future['videoCount']!==null)
        p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'collector'=>'youtube_v1','sourceType'=>'youtube_api','sourceReference'=>$official['normalized_url'],'observedAt'=>$observedAt,'followers'=>$followers,'metrics'=>$future,'qualityStatus'=>$invalidFuture?'quarantined':'usable','confidence'=>98,'runUuid'=>$runUuid,'rawPayloadHash'=>hash('sha256',(string)$response['body']),'provenance'=>$prov]);
    $uploads=(string)($channel['contentDetails']['relatedPlaylists']['uploads']??'');if($uploads==='')return;
    $playlist=p50_mc_request($fetch,'https://www.googleapis.com/youtube/v3/playlistItems?'.http_build_query(['part'=>'snippet,contentDetails','playlistId'=>$uploads,'maxResults'=>$limit,'key'=>$key]),[],$result);
    $playlistStatus=(int)$playlist['status'];
    if($playlistStatus<200||$playlistStatus>=300){
        $result['rateLimited']=$playlistStatus===429;$result['status']='partial';
        $result['errors'][]='YouTube playlistItems.list '.($playlistStatus===429?'rate_limited':($playlistStatus===403?'forbidden':'http_error'));
        return;
    }
    $ids=[];
    foreach((array)(p50_mc_json($playlist)['items']??[]) as $item){$id=(string)($item['contentDetails']['videoId']??'');if($id!=='')$ids[]=$id;}
    if(!$ids)return;
    $videos=p50_mc_request($fetch,'https://www.googleapis.com/youtube/v3/videos?'.http_build_query(['part'=>'snippet,statistics,status,contentDetails,liveStreamingDetails','id'=>implode(',',$ids),'key'=>$key]),[],$result);
    $videosStatus=(int)$videos['status'];
    if($videosStatus<200||$videosStatus>=300){
        $result['rateLimited']=$videosStatus===429;$result['status']='partial';
        $result['errors'][]='YouTube videos.list '.($videosStatus===429?'rate_limited':($videosStatus===403?'forbidden':'http_error'));
        return;
    }
    foreach((array)(p50_mc_json($videos)['items']??[]) as $video){
        $id=(string)($video['id']??'');if($id===''||($video['status']['privacyStatus']??'public')!=='public')continue;
        [$contentType,$youtubeFormat,$shortCandidate]=p50_mc_youtube_content_type($video);
        $vp=p50_mc_provenance('YouTube','youtube_api','videos.list',$official,gmdate('c'),(int)$videos['status'],$runUuid,'api');
        $contentUrl=$contentType==='short'?'https://www.youtube.com/shorts/'.$id:'https://www.youtube.com/watch?v='.$id;
        $content=p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'platformContentId'=>$id,'contentType'=>$contentType,'canonicalUrl'=>$contentUrl,'title'=>(string)($video['snippet']['title']??''),'publishedAt'=>$video['snippet']['publishedAt']??null,'confidence'=>98,'sourceType'=>'youtube_api','observedAt'=>$observedAt,'metadata'=>['youtubeFormat'=>$youtubeFormat,'shortCandidate'=>$shortCandidate],'provenance'=>$vp]);
        $result['contentsFound']++;$vs=(array)($video['statistics']??[]);
        $views=p50_mc_int($vs,'viewCount');$likes=p50_mc_int($vs,'likeCount');$comments=p50_mc_int($vs,'commentCount');
        foreach([$views,$likes,$comments] as $metric)if($metric===null)$result['unavailableMetrics']++;
        if($views===null&&$likes===null&&$comments===null)continue;
        p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'youtube_v1','sourceType'=>'youtube_api','sourceReference'=>'https://www.youtube.com/watch?v='.$id,'observedAt'=>$observedAt,'views'=>$views,'likes'=>$likes,'comments'=>$comments,'shares'=>null,'saves'=>null,'confidence'=>98,'runUuid'=>$runUuid,'rawPayloadHash'=>hash('sha256',p50_metrics_json($video)),'provenance'=>$vp]);
    }
}

function p50_mc_youtube_fallback(PDO $pdo,array $official,string $kind,string $identifier,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $channelId=$kind==='id'?$identifier:'';
    if($channelId===''){
        $page=p50_mc_request($fetch,$official['normalized_url'],[],$result);
        if(preg_match('/(?:channelId|externalId)["\':\s]+(UC[A-Za-z0-9_-]+)/',(string)$page['body'],$m))$channelId=$m[1];
    }
    if($channelId===''){$result['status']='unavailable_or_blocked';return;}
    $prov=p50_mc_provenance('YouTube','youtube_public_feed','YouTube Atom feed',$official,gmdate('c'),200,$runUuid,'public_fallback');
    $account=p50_metrics_upsert_account($pdo,['profileId'=>$official['profile_id'],'platform'=>'YouTube','platformAccountId'=>$channelId,'handle'=>$kind==='handle'?$identifier:null,'canonicalUrl'=>$official['normalized_url'],'confidence'=>$official['confidence'],'sourceType'=>'youtube_public_feed','observedAt'=>$observedAt,'provenance'=>$prov]);
    $result['accountFound']=true;$feed=p50_mc_request($fetch,'https://www.youtube.com/feeds/videos.xml?channel_id='.rawurlencode($channelId),[],$result);
    if((int)$feed['status']<200||(int)$feed['status']>=300)return;
    preg_match_all('#<entry>.*?<yt:videoId>([^<]+)</yt:videoId>.*?<title>(.*?)</title>.*?<published>([^<]+)</published>.*?</entry>#s',(string)$feed['body'],$matches,PREG_SET_ORDER);
    foreach(array_slice($matches,0,$limit) as $match){p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'platformContentId'=>html_entity_decode($match[1]),'contentType'=>'video','canonicalUrl'=>'https://www.youtube.com/watch?v='.html_entity_decode($match[1]),'title'=>html_entity_decode(strip_tags($match[2])),'publishedAt'=>$match[3],'confidence'=>90,'sourceType'=>'youtube_public_feed','observedAt'=>$observedAt,'provenance'=>$prov]);$result['contentsFound']++;}
    $result['unavailableMetrics']++;
}

function p50_mc_x_handle(string $url): string {
    $path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
    return preg_match('/^[A-Za-z0-9_]{1,15}$/',$path)?strtolower($path):'';
}

function p50_mc_x(PDO $pdo,array $official,int $limit,string $observedAt,string $runUuid,callable $fetch,array &$result): void {
    $handle=p50_mc_x_handle($official['normalized_url']);if($handle==='')throw new InvalidArgumentException('Lien X officiel non reconnu.');
    $token=p50_mc_config('X');if($token===''){$result['status']='unavailable_or_blocked';$result['unavailableMetrics']++;return;}
    $headers=['Authorization: Bearer '.$token];
    $userResponse=p50_mc_request($fetch,'https://api.x.com/2/users/by/username/'.rawurlencode($handle).'?user.fields=public_metrics',$headers,$result);
    $status=(int)$userResponse['status'];if($status===429){$result['rateLimited']=true;$result['status']='unavailable_or_blocked';return;}if($status<200||$status>=300){$result['status']='unavailable_or_blocked';return;}
    $user=p50_mc_json($userResponse)['data']??null;if(!is_array($user)){$result['status']='unavailable_or_blocked';return;}
    $id=(string)($user['id']??'');$um=(array)($user['public_metrics']??[]);$prov=p50_mc_provenance('X','x_api','users/by/username',$official,gmdate('c'),$status,$runUuid,'api');
    $account=p50_metrics_upsert_account($pdo,['profileId'=>$official['profile_id'],'platform'=>'X','platformAccountId'=>$id,'handle'=>$handle,'canonicalUrl'=>$official['normalized_url'],'confidence'=>$official['confidence'],'sourceType'=>'x_api','observedAt'=>$observedAt,'provenance'=>$prov]);$result['accountFound']=true;
    $followers=p50_mc_int($um,'followers_count');[$future,$invalidFuture]=p50_mc_future_metrics(['following'=>p50_mc_int($um,'following_count'),'postCount'=>p50_mc_int($um,'tweet_count')]);$following=$future['following'];$postCount=$future['postCount'];
    if($followers!==null||$following!==null||$postCount!==null)
        p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'collector'=>'x_v1','sourceType'=>'x_api','sourceReference'=>$official['normalized_url'],'observedAt'=>$observedAt,'followers'=>$followers,'metrics'=>['following'=>$following,'postCount'=>$postCount],'qualityStatus'=>$invalidFuture?'quarantined':'usable','confidence'=>96,'runUuid'=>$runUuid,'rawPayloadHash'=>hash('sha256',(string)$userResponse['body']),'provenance'=>$prov]);
    $tweets=p50_mc_request($fetch,'https://api.x.com/2/users/'.rawurlencode($id).'/tweets?'.http_build_query(['max_results'=>max(5,$limit),'exclude'=>'retweets,replies','tweet.fields'=>'created_at,public_metrics']),$headers,$result);
    $tweetStatus=(int)$tweets['status'];
    if($tweetStatus<200||$tweetStatus>=300){
        $result['rateLimited']=$tweetStatus===429;$result['status']='partial';
        $result['errors'][]='X users/:id/tweets '.($tweetStatus===429?'rate_limited':($tweetStatus===403?'forbidden':'http_error'));
        return;
    }
    foreach(array_slice((array)(p50_mc_json($tweets)['data']??[]),0,$limit) as $tweet){
        $tid=(string)($tweet['id']??'');if($tid==='')continue;$tm=(array)($tweet['public_metrics']??[]);
        $tp=p50_mc_provenance('X','x_api','users/:id/tweets',$official,gmdate('c'),(int)$tweets['status'],$runUuid,'api');
        $url='https://x.com/'.$handle.'/status/'.$tid;$content=p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'platformContentId'=>$tid,'contentType'=>'post','canonicalUrl'=>$url,'title'=>substr((string)($tweet['text']??''),0,500),'publishedAt'=>$tweet['created_at']??null,'confidence'=>96,'sourceType'=>'x_api','observedAt'=>$observedAt,'provenance'=>$tp]);$result['contentsFound']++;
        $views=p50_mc_int($tm,'impression_count');$likes=p50_mc_int($tm,'like_count');$replies=p50_mc_int($tm,'reply_count');$reposts=p50_mc_int($tm,'retweet_count');
        if($views===null&&$likes===null&&$replies===null&&$reposts===null){$result['unavailableMetrics']+=4;continue;}
        [$detail,$invalidDetail]=p50_mc_future_metrics(['quotes'=>p50_mc_int($tm,'quote_count'),'bookmarks'=>p50_mc_int($tm,'bookmark_count')]);
        p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>'x_v1','sourceType'=>'x_api','sourceReference'=>$url,'observedAt'=>$observedAt,'views'=>$views,'likes'=>$likes,'comments'=>$replies,'shares'=>$reposts,'metrics'=>$detail,'qualityStatus'=>$invalidDetail?'quarantined':'usable','confidence'=>96,'runUuid'=>$runUuid,'rawPayloadHash'=>hash('sha256',p50_metrics_json($tweet)),'provenance'=>$tp]);
    }
}

require_once __DIR__.'/metrics-social-collectors-core.php';

function p50_metrics_collect_profile(PDO $pdo,string $profileId,string $platform,int $contentLimit=5,?callable $fetch=null,?string $observedAt=null,array $options=[]): array {
    $platform=p50_mc_platform($platform);if($platform==='')throw new InvalidArgumentException('Plateforme non prise en charge.');
    $contentLimit=max(1,min(P50_METRICS_COLLECTOR_CONTENTS_MAX,$contentLimit));$observedAt=p50_metrics_timestamp($observedAt??gmdate('c'));$fetch=$fetch??'p50_mc_http';
    p50_metrics_ensure_schema($pdo);
    $run=p50_metrics_start_run($pdo,['collector'=>strtolower($platform).'_v1','platform'=>$platform,'jobUuid'=>($options['jobUuid']??null)?:null,'triggerType'=>(string)($options['triggerType']??'manual'),'metadata'=>['profileId'=>$profileId,'collectorVersion'=>P50_METRICS_COLLECTOR_VERSION,'cadence'=>$options['cadence']??null]]);
    $result=p50_mc_result($platform,$profileId,$observedAt,$run['runUuid']);
    try{
        $official=p50_mc_official($pdo,$profileId,$platform);
        p50_mc_dispatch($platform)($pdo,$official,$contentLimit,$observedAt,$run['runUuid'],$fetch,$result);
        if($result['status']==='running')$result['status']=empty($result['errors'])?'success':'partial';
    }catch(Throwable $error){$result['errors'][]=p50_metrics_safe_error($error->getMessage());$result['status']=$result['accountFound']?'partial':'error';}
    $result['finishedAt']=gmdate('Y-m-d H:i:s');
    p50_metrics_finish_run($pdo,$run['runUuid'],$result['status'],['accountsProcessed'=>(int)$result['accountFound'],'contentsFound'=>$result['contentsFound'],'capturesRecorded'=>$result['capturesRecorded'],'duplicatesSkipped'=>$result['duplicatesSkipped'],'quarantinedCount'=>$result['quarantined'],'errorCount'=>count($result['errors'])],$result['errors'][0]??null,['profileId'=>$profileId,'requestsAttempted'=>$result['requestsAttempted'],'requestsSucceeded'=>$result['requestsSucceeded'],'rateLimited'=>$result['rateLimited'],'unavailableMetrics'=>$result['unavailableMetrics']]);
    return $result;
}

function p50_metrics_collect_batch(PDO $pdo,string $platform,int $profileLimit=10,int $contentLimit=5,?callable $fetch=null): array {
    $platform=p50_mc_platform($platform);if($platform==='')throw new InvalidArgumentException('Plateforme non prise en charge.');
    $profileLimit=max(1,min(P50_METRICS_COLLECTOR_PROFILES_MAX,$profileLimit));$contentLimit=max(1,min(5,$contentLimit));
    global $config;$threshold=max(90,min(100,(int)($config['data_engine']['confidence_threshold']??90)));
    $stmt=$pdo->prepare("SELECT r.profile_id FROM p50_profile_registry r JOIN p50_social_links s ON s.profile_id=r.profile_id WHERE r.alive=1 AND s.platform=? AND s.status='verified' AND s.confidence>=? ORDER BY r.profile_id LIMIT ".$profileLimit);$stmt->execute([$platform,$threshold]);
    $details=[];foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $profileId){
        $profileId=(string)$profileId;$lock='pass50_metrics_collect_'.strtolower($platform).'_'.hash('sha256',$profileId);
        if((int)p50_metrics_value($pdo,"SELECT GET_LOCK(?,2)",[$lock])!==1){$details[]=p50_mc_result($platform,$profileId,gmdate('Y-m-d H:i:s'),'');$details[array_key_last($details)]['status']='unavailable_or_blocked';$details[array_key_last($details)]['errors'][]='Une collecte identique est déjà en cours.';continue;}
        try{$details[]=p50_metrics_collect_profile($pdo,$profileId,$platform,$contentLimit,$fetch);}
        finally{try{$release=$pdo->prepare("SELECT RELEASE_LOCK(?)");$release->execute([$lock]);}catch(Throwable){}}
    }
    return p50_mc_summary($details)+['details'=>$details];
}

function p50_mc_summary(array $details): array {
    $summary=['profiles'=>count($details),'accountsProcessed'=>0,'contentsFound'=>0,'capturesRecorded'=>0,'duplicatesSkipped'=>0,'quarantined'=>0,'unavailableProfiles'=>0,'errors'=>0];
    foreach($details as $row){$summary['accountsProcessed']+=(int)$row['accountFound'];foreach(['contentsFound','capturesRecorded','duplicatesSkipped','quarantined'] as $key)$summary[$key]+=(int)$row[$key];$summary['unavailableProfiles']+=(int)($row['status']==='unavailable_or_blocked');$summary['errors']+=count($row['errors']);}
    return ['summary'=>$summary];
}

function p50_metrics_collectors_status(PDO $pdo): array {
    $access=[];foreach(['YouTube','X','TikTok','Instagram','Facebook','Snapchat'] as $platform)$access[strtolower($platform)]=p50_mc_public_access($platform,'');
    $empty=static fn(array $item): array=>$item+['accounts'=>null,'contents'=>null,'captures'=>null,'usableCaptures'=>null,'quarantinedCaptures'=>null,'latestCaptureAt'=>null,'captures24h'=>null,'lastRun'=>null,'lastStatus'=>'schema_not_installed','lastError'=>null,'rateLimitedCount'=>0,'unavailableProfiles'=>0];
    if(!p50_metrics_table_exists($pdo,'p50_metric_runs'))return [
      'youtube'=>$empty($access['youtube']),'x'=>$empty($access['x']),'tiktok'=>$empty($access['tiktok']),
      'instagram'=>$empty($access['instagram']),'facebook'=>$empty($access['facebook']),'snapchat'=>$empty($access['snapchat']),
    ];
    $out=[];foreach(['YouTube'=>'youtube_v1','X'=>'x_v1','TikTok'=>'tiktok_v1','Instagram'=>'instagram_v1','Facebook'=>'facebook_v1','Snapchat'=>'snapchat_v1'] as $platform=>$collector){
        $stmt=$pdo->prepare("SELECT run_uuid,status,error_message,started_at,finished_at,metadata_json FROM p50_metric_runs WHERE collector=? ORDER BY started_at DESC LIMIT 1");$stmt->execute([$collector]);$last=$stmt->fetch()?:null;$metadata=$last?json_decode((string)$last['metadata_json'],true):[];
        $out[strtolower($platform)]=[
          'configured'=>$access[strtolower($platform)]['configured'],'authorized'=>$access[strtolower($platform)]['authorized'],
          'mode'=>$access[strtolower($platform)]['mode'],'authorizationRequired'=>$access[strtolower($platform)]['authorizationRequired'],
          'accounts'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_accounts WHERE platform=?",[$platform]),
          'contents'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_contents WHERE platform=?",[$platform]),
          'captures'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_captures WHERE platform=?",[$platform]),
          'usableCaptures'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_captures WHERE platform=? AND quality_status='usable'",[$platform]),
          'quarantinedCaptures'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_captures WHERE platform=? AND quality_status='quarantined'",[$platform]),
          'latestCaptureAt'=>p50_metrics_value($pdo,"SELECT MAX(captured_at) FROM p50_metric_captures WHERE platform=?",[$platform])?:null,
          'captures24h'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_captures WHERE platform=? AND captured_at>=UTC_TIMESTAMP()-INTERVAL 24 HOUR",[$platform]),
          'lastRun'=>$last,'lastStatus'=>$last['status']??null,'lastError'=>$last['error_message']??null,
          'rateLimitedCount'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_runs WHERE collector=? AND metadata_json LIKE '%\"rateLimited\":true%' ",[$collector]),
          'unavailableProfiles'=>(int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_runs WHERE collector=? AND status IN ('unavailable_or_blocked','authorization_required','configuration_missing','unsupported_account_type')",[$collector]),
        ];
    }return $out;
}
