<?php
declare(strict_types=1);
require_once __DIR__.'/metrics-core.php';

const P50_RADAR_STATUSES = [
    'collected','no_recent_content','public_metrics_unavailable','invalid_url',
    'unsupported_url','rate_limited','temporarily_unavailable','timeout','http_error',
    'content_removed_or_private','detected_content_inaccessible','budget_exceeded','duplicate','error',
];

function p50_radar_begin_batch(int $batchLimit=20,int $profileLimit=5): void {
    p50_network_begin_cycle($batchLimit,$profileLimit,4);
    $GLOBALS['p50_youtube_run']=[
        'videos'=>[],'channels'=>[],'apiRequests'=>0,'apiCacheHits'=>0,
        'quotaLimit'=>20,'configured'=>p50_radar_youtube_key()!=='',
    ];
    if(!$GLOBALS['p50_youtube_run']['configured']){
        error_log('PASS50 Radar: YouTube Data API v3 non configurée (PASS50_YOUTUBE_API_KEY absente) ; collecte publique uniquement.');
    }
}

function p50_radar_begin_profile(): void {
    p50_network_begin_profile();
}

function p50_radar_network_status(array $response): string {
    return (string)($response['collectionStatus']??p50_network_failure_status((int)($response['status']??0),(string)($response['error']??'')));
}

function p50_radar_fetch(string $url,string $accept='text/html,*/*;q=0.6',bool $allowRetry=true): array {
    return p50_http_fetch($url,4,$accept);
}

function p50_radar_ensure_schema(): void {
    static $done=false;if($done)return;$done=true;
    db()->exec("CREATE TABLE IF NOT EXISTS p50_radar_collection_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        profile_id VARCHAR(100) NOT NULL,
        platform VARCHAR(32) NOT NULL,
        official_url TEXT NULL,
        collection_status VARCHAR(40) NOT NULL,
        publications_detected SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        captures_recorded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        error_message VARCHAR(500) NULL,
        metadata LONGTEXT NULL,
        collected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_p50_radar_profile_date(profile_id,collected_at),
        INDEX idx_p50_radar_status_date(collection_status,collected_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS p50_radar_metric_captures (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id BIGINT UNSIGNED NULL,
        profile_id VARCHAR(100) NOT NULL,
        platform VARCHAR(32) NOT NULL,
        content_key CHAR(64) CHARACTER SET ascii NOT NULL,
        content_id VARCHAR(191) NULL,
        canonical_url TEXT NOT NULL,
        published_at DATETIME NULL,
        metrics LONGTEXT NOT NULL,
        metric_deltas LONGTEXT NOT NULL,
        captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_p50_radar_capture_event(event_id,captured_at),
        INDEX idx_p50_radar_capture_content(profile_id,platform,content_key,captured_at),
        INDEX idx_p50_radar_capture_date(captured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS p50_youtube_api_cache (
        cache_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,
        resource_type VARCHAR(32) NOT NULL,
        response_json LONGTEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_p50_youtube_cache_expiry(expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $column=db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_radar_metric_captures' AND COLUMN_NAME='event_id'")->fetchColumn();
    if((int)$column===0)db()->exec("ALTER TABLE p50_radar_metric_captures ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER id");
    db()->exec("INSERT INTO p50_activity_events(profile_id,platform,event_type,title,url,url_hash,published_at,metrics,confidence,status,collected_at)
        SELECT c.profile_id,c.platform,'radar','Contenu Radar historique',c.canonical_url,c.content_key,c.published_at,c.metrics,90,'verified',c.captured_at
        FROM p50_radar_metric_captures c
        LEFT JOIN p50_activity_events e ON e.profile_id=c.profile_id AND e.url_hash=c.content_key
        WHERE c.event_id IS NULL AND e.id IS NULL AND c.canonical_url<>'' AND c.content_key<>''
        ON DUPLICATE KEY UPDATE url_hash=VALUES(url_hash)");
    db()->exec("UPDATE p50_radar_metric_captures c JOIN p50_activity_events e ON e.profile_id=c.profile_id AND e.url_hash=c.content_key SET c.event_id=e.id WHERE c.event_id IS NULL");
    $index=db()->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p50_radar_metric_captures' AND INDEX_NAME='idx_p50_radar_capture_event'")->fetchColumn();
    if((int)$index===0)db()->exec("CREATE INDEX idx_p50_radar_capture_event ON p50_radar_metric_captures(event_id,captured_at)");
}

function p50_radar_empty_metrics(): array {
    return ['views'=>null,'likes'=>null,'comments'=>null,'shares'=>null,'saves'=>null,'followers'=>null];
}

function p50_radar_metrics(array $metrics): array {
    $out=p50_radar_empty_metrics();
    foreach($out as $key=>$_){
        if(!array_key_exists($key,$metrics)||$metrics[$key]===null||$metrics[$key]==='')continue;
        if(!is_numeric($metrics[$key]))continue;
        $out[$key]=max(0,(int)$metrics[$key]);
    }
    return $out;
}

function p50_radar_present_metrics(array $metrics): array {
    return array_filter(p50_radar_metrics($metrics),static fn($value)=>$value!==null);
}

function p50_radar_result(string $profileId,string $platform,string $status,array $values=[]): array {
    if(!in_array($status,P50_RADAR_STATUSES,true))$status='error';
    return [
        'profileId'=>$profileId,
        'platform'=>$platform,
        'contentId'=>(string)($values['contentId']??''),
        'canonicalUrl'=>(string)($values['canonicalUrl']??''),
        'publishedAt'=>$values['publishedAt']??null,
        'collectedAt'=>gmdate('c'),
        'metrics'=>p50_radar_metrics((array)($values['metrics']??[])),
        'confidence'=>max(0,min(100,(int)($values['confidence']??0))),
        'source'=>(string)($values['source']??'public'),
        'collectionStatus'=>$status,
        'accessStatus'=>(string)($values['accessStatus']??''),
        'title'=>(string)($values['title']??''),
        'channelId'=>(string)($values['channelId']??''),
        'channel'=>(string)($values['channel']??''),
        'subscribers'=>isset($values['subscribers'])&&is_numeric($values['subscribers'])?max(0,(int)$values['subscribers']):null,
        'error'=>(string)($values['error']??''),
    ];
}

function p50_radar_youtube_reference(string $url): array {
    $canonical=p50_de_normalize_activity_url($url);
    $host=strtolower((string)(parse_url($canonical,PHP_URL_HOST)?:''));
    $path=trim((string)(parse_url($canonical,PHP_URL_PATH)?:''),'/');
    $query=[];parse_str((string)(parse_url($canonical,PHP_URL_QUERY)?:''),$query);
    if(!in_array($host,['youtube.com','m.youtube.com','youtu.be'],true))return ['kind'=>'invalid','id'=>'','canonicalUrl'=>$canonical];
    if($host==='youtu.be'&&preg_match('#^([A-Za-z0-9_-]{6,})#',$path,$m))return ['kind'=>'video','id'=>$m[1],'canonicalUrl'=>'https://youtube.com/watch?v='.$m[1]];
    if(!empty($query['v'])&&preg_match('/^[A-Za-z0-9_-]{6,}$/',(string)$query['v']))return ['kind'=>'video','id'=>(string)$query['v'],'canonicalUrl'=>'https://youtube.com/watch?v='.(string)$query['v']];
    if(preg_match('#^(shorts|live|embed)/([A-Za-z0-9_-]{6,})#',$path,$m))return ['kind'=>$m[1]==='shorts'?'short':'video','id'=>$m[2],'canonicalUrl'=>'https://youtube.com/watch?v='.$m[2]];
    if(preg_match('#^channel/([A-Za-z0-9_-]+)$#',$path,$m))return ['kind'=>'channel','id'=>$m[1],'canonicalUrl'=>'https://youtube.com/channel/'.$m[1]];
    if(preg_match('#^@([A-Za-z0-9._-]+)$#',$path,$m))return ['kind'=>'handle','id'=>$m[1],'canonicalUrl'=>'https://youtube.com/@'.$m[1]];
    if(preg_match('#^(?:c|user)/([A-Za-z0-9._-]+)$#',$path,$m))return ['kind'=>'legacy','id'=>$m[1],'canonicalUrl'=>'https://youtube.com/'.$path];
    return ['kind'=>'unsupported','id'=>'','canonicalUrl'=>$canonical];
}

function p50_radar_youtube_key(): string {
    return trim((string)(getenv('PASS50_YOUTUBE_API_KEY')?:''));
}

function p50_radar_youtube_status(): array {
    $run=$GLOBALS['p50_youtube_run']??[];
    return [
        'configured'=>(bool)($run['configured']??(p50_radar_youtube_key()!=='')),
        'mode'=>!empty($run['configured'])?'youtube_data_api_v3':'public_only',
        'message'=>!empty($run['configured'])?'YouTube Data API v3 configurée.':'API non configurée : PASS50_YOUTUBE_API_KEY absente, données publiques uniquement.',
        'apiRequests'=>(int)($run['apiRequests']??0),
        'cacheHits'=>(int)($run['apiCacheHits']??0),
        'quotaLimit'=>(int)($run['quotaLimit']??20),
    ];
}

function p50_radar_youtube_api(string $resource,array $params,int $ttlSeconds): array {
    $key=p50_radar_youtube_key();
    if($key==='')throw new RuntimeException('api_not_configured');
    ksort($params);
    $cacheKey=hash('sha256',$resource."\n".http_build_query($params));
    $stmt=db()->prepare('SELECT response_json FROM p50_youtube_api_cache WHERE cache_key=? AND expires_at>UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([$cacheKey]);$cached=$stmt->fetchColumn();
    if(is_string($cached)&&$cached!==''){
        $data=json_decode($cached,true);
        if(is_array($data)){
            $GLOBALS['p50_youtube_run']['apiCacheHits']++;
            return $data;
        }
    }
    $run=&$GLOBALS['p50_youtube_run'];
    if((int)$run['apiRequests']>=(int)$run['quotaLimit'])throw new RuntimeException('budget_exceeded');
    $run['apiRequests']++;
    $url='https://www.googleapis.com/youtube/v3/'.$resource.'?'.http_build_query($params+['key'=>$key]);
    $response=p50_radar_fetch($url,'application/json');
    if(!$response['ok']||$response['body']===''){
        $status=p50_radar_network_status($response);
        if((int)($response['status']??0)===403)$status='rate_limited';
        throw new RuntimeException($status);
    }
    $data=json_decode((string)$response['body'],true);
    if(!is_array($data)||isset($data['error']))throw new RuntimeException('temporarily_unavailable');
    $expires=gmdate('Y-m-d H:i:s',time()+max(60,$ttlSeconds));
    db()->prepare('INSERT INTO p50_youtube_api_cache(cache_key,resource_type,response_json,expires_at,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE response_json=VALUES(response_json),expires_at=VALUES(expires_at),updated_at=UTC_TIMESTAMP()')
        ->execute([$cacheKey,$resource,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$expires]);
    return $data;
}

function p50_radar_json(string $url): array {
    $r=p50_radar_fetch($url,'application/json');
    if(!$r['ok']||$r['body']==='')throw new RuntimeException((string)$r['collectionStatus']);
    $data=json_decode($r['body'],true);
    if(!is_array($data))throw new RuntimeException('error');
    return $data;
}

function p50_radar_youtube_channel_details(string $channelId): array {
    if($channelId==='')return [];
    if(isset($GLOBALS['p50_youtube_run']['channels'][$channelId]))return $GLOBALS['p50_youtube_run']['channels'][$channelId];
    $data=p50_radar_youtube_api('channels',['part'=>'snippet,statistics,contentDetails','id'=>$channelId],21600);
    $channel=(array)($data['items'][0]??[]);
    $stats=(array)($channel['statistics']??[]);
    $details=[
        'id'=>(string)($channel['id']??$channelId),
        'title'=>(string)($channel['snippet']['title']??''),
        'subscribers'=>empty($stats['hiddenSubscriberCount'])&&isset($stats['subscriberCount'])?(int)$stats['subscriberCount']:null,
        'uploads'=>(string)($channel['contentDetails']['relatedPlaylists']['uploads']??''),
    ];
    $GLOBALS['p50_youtube_run']['channels'][$channelId]=$details;
    return $details;
}

function p50_radar_json_headers(string $url,array $headers): array {
    $response=p50_http_fetch($url,4,'application/json',false,$headers);
    if(!$response['ok']||$response['body']==='')throw new RuntimeException(p50_radar_network_status($response));
    $data=json_decode((string)$response['body'],true);
    if(!is_array($data))throw new RuntimeException('error');
    return $data;
}

function p50_radar_content_document(string $url,string $platform='Web'): array {
    $r=p50_radar_fetch($url,'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
    if(!$r['ok']||$r['body']==='')return ['ok'=>false,'status'=>$r['collectionStatus'],'metrics'=>[],'publishedAt'=>null,'title'=>'','canonicalUrl'=>p50_de_normalize_activity_url($url),'isPublication'=>false];
    $html=(string)$r['body'];$final=$r['finalUrl']?:$url;$meta=p50_page_metadata($html,$final);$metrics=[];$published=null;
    $patterns=[
        'views'=>['/"viewCount"\s*:\s*"?(\d+)/i','/"playCount"\s*:\s*"?(\d+)/i','/"videoViewCount"\s*:\s*"?(\d+)/i'],
        'likes'=>['/"likeCount"\s*:\s*"?(\d+)/i','/"diggCount"\s*:\s*"?(\d+)/i'],
        'comments'=>['/"commentCount"\s*:\s*"?(\d+)/i'],
        'shares'=>['/"shareCount"\s*:\s*"?(\d+)/i'],
    ];
    foreach($patterns as $key=>$list)foreach($list as $pattern)if(preg_match($pattern,$html,$match)){$metrics[$key]=(int)$match[1];break;}
    foreach(['/"datePublished"\s*:\s*"([^"]+)"/i','/"uploadDate"\s*:\s*"([^"]+)"/i','/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)/i'] as $pattern){
        if(preg_match($pattern,$html,$match)){try{$published=(new DateTimeImmutable($match[1]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable){}break;}
    }
    $ogType=p50_meta($html,'og:type');$structured=(bool)preg_match('/"@type"\s*:\s*"(?:Article|NewsArticle|BlogPosting|VideoObject|SocialMediaPosting)"/i',$html);
    $exactSocial=$platform!=='Web'&&p50_de_is_exact_social_content($platform,$final);
    $path=trim((string)(parse_url($final,PHP_URL_PATH)?:''),'/');
    $isPublication=$path!==''&&$path!=='index.php'&&($structured||in_array(strtolower($ogType),['article','video.other','video'],true)||$exactSocial);
    return ['ok'=>true,'status'=>'collected','metrics'=>$metrics,'publishedAt'=>$published,'title'=>trim((string)($meta['title']??'')),'canonicalUrl'=>p50_de_normalize_activity_url($final),'isPublication'=>$isPublication];
}

function p50_radar_youtube_video(string $profileId,string $videoId,int $confidence,string $source='YouTube public'): array {
    if(isset($GLOBALS['p50_youtube_run']['videos'][$videoId])){
        $cached=$GLOBALS['p50_youtube_run']['videos'][$videoId];
        $cached['profileId']=$profileId;$cached['confidence']=$confidence;$cached['collectedAt']=gmdate('c');
        return $cached;
    }
    $url='https://youtube.com/watch?v='.$videoId;$metrics=[];$published=null;$title='';
    $channelId='';$channel='';$subscribers=null;
    $key=p50_radar_youtube_key();
    if($key!==''){
        try{
            $data=p50_radar_youtube_api('videos',['part'=>'snippet,statistics','id'=>$videoId],900);
            $video=$data['items'][0]??null;
            if(!is_array($video))throw new RuntimeException('temporarily_unavailable');
            $stats=(array)($video['statistics']??[]);
            $metrics=['views'=>$stats['viewCount']??null,'likes'=>$stats['likeCount']??null,'comments'=>$stats['commentCount']??null];
            $snippet=(array)($video['snippet']??[]);
            $published=(string)($snippet['publishedAt']??'')?:null;$title=(string)($snippet['title']??'');
            $channelId=(string)($snippet['channelId']??'');$channel=(string)($snippet['channelTitle']??'');
            if($channelId!==''){
                try{
                    $channelDetails=p50_radar_youtube_channel_details($channelId);
                    $channel=(string)($channelDetails['title']??$channel);
                    $subscribers=$channelDetails['subscribers']??null;
                    $metrics['followers']=$subscribers;
                }catch(Throwable){}
            }
            $source='YouTube Data API v3';
        }catch(Throwable $apiError){
            $content=p50_radar_content_document($url,'YouTube');
            if(!$content['ok']){
                $failure=in_array($apiError->getMessage(),P50_RADAR_STATUSES,true)?$apiError->getMessage():$content['status'];
                $result=p50_radar_result($profileId,'YouTube',$failure,['canonicalUrl'=>$url,'contentId'=>$videoId,'source'=>'YouTube Data API v3 indisponible ; repli public']);
                $GLOBALS['p50_youtube_run']['videos'][$videoId]=$result;
                return $result;
            }
            $metrics=(array)$content['metrics'];$published=$content['publishedAt'];$title=(string)$content['title'];
            $source='Page YouTube publique (repli API)';
        }
    }else{
        $content=p50_radar_content_document($url,'YouTube');
        if(!$content['ok'])return p50_radar_result($profileId,'YouTube',$content['status'],['canonicalUrl'=>$url,'contentId'=>$videoId,'source'=>'Page YouTube publique']);
        $metrics=(array)$content['metrics'];$published=$content['publishedAt'];$title=(string)$content['title'];
    }
    $status=p50_radar_present_metrics($metrics)?'collected':'public_metrics_unavailable';
    $result=p50_radar_result($profileId,'YouTube',$status,['contentId'=>$videoId,'canonicalUrl'=>$url,'publishedAt'=>$published,'metrics'=>$metrics,'confidence'=>$confidence,'source'=>$source,'title'=>$title,'channelId'=>$channelId,'channel'=>$channel,'subscribers'=>$subscribers]);
    $GLOBALS['p50_youtube_run']['videos'][$videoId]=$result;
    return $result;
}

function p50_radar_youtube_channel_id(array $reference): string {
    if($reference['kind']==='channel')return (string)$reference['id'];
    if(p50_radar_youtube_key()!==''&&in_array($reference['kind'],['handle','legacy'],true)){
        $lookup=$reference['kind']==='handle'?['forHandle'=>(string)$reference['id']]:['forUsername'=>(string)$reference['id']];
        try{
            $data=p50_radar_youtube_api('channels',['part'=>'id']+$lookup,21600);
            $id=(string)($data['items'][0]['id']??'');
            if($id!=='')return $id;
        }catch(Throwable){}
    }
    $r=p50_radar_fetch((string)$reference['canonicalUrl'],'text/html,*/*;q=0.7');
    if(!$r['ok'])return '';
    foreach([
        '/"channelId"\s*:\s*"(UC[A-Za-z0-9_-]{20,})"/',
        '/itemprop="channelId"\s+content="(UC[A-Za-z0-9_-]{20,})"/i',
        '/youtube\.com\/channel\/(UC[A-Za-z0-9_-]{20,})/',
    ] as $pattern)if(preg_match($pattern,$r['body'],$match))return $match[1];
    return '';
}

function p50_radar_youtube_adapter(string $profileId,string $url,int $confidence): array {
    $ref=p50_radar_youtube_reference($url);
    if($ref['kind']==='invalid')return [p50_radar_result($profileId,'YouTube','invalid_url',['canonicalUrl'=>$ref['canonicalUrl']])];
    if(in_array($ref['kind'],['video','short'],true))return [p50_radar_youtube_video($profileId,$ref['id'],$confidence)];
    $channelId=p50_radar_youtube_channel_id($ref);
    if($channelId==='')return [p50_radar_result($profileId,'YouTube','unsupported_url',['canonicalUrl'=>$ref['canonicalUrl']])];
    $feed=p50_radar_fetch('https://www.youtube.com/feeds/videos.xml?channel_id='.rawurlencode($channelId),'application/atom+xml,application/xml');
    if(!$feed['ok']||$feed['body']==='')return [p50_radar_result($profileId,'YouTube',$feed['collectionStatus'],['canonicalUrl'=>$ref['canonicalUrl']])];
    if(!function_exists('simplexml_load_string'))return [p50_radar_result($profileId,'YouTube','temporarily_unavailable',['canonicalUrl'=>$ref['canonicalUrl']])];
    libxml_use_internal_errors(true);$xml=simplexml_load_string($feed['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if(!$xml)return [p50_radar_result($profileId,'YouTube','temporarily_unavailable',['canonicalUrl'=>$ref['canonicalUrl']])];
    $ns=$xml->getNamespaces(true);$out=[];
    foreach($xml->entry as $entry){
        $yt=isset($ns['yt'])?$entry->children($ns['yt']):null;$id=(string)($yt?->videoId??'');
        $published=(string)$entry->published;$ts=strtotime($published);
        if($id===''||$ts===false||$ts<time()-7*86400)continue;
        $out[]=p50_radar_youtube_video($profileId,$id,$confidence,'YouTube Atom + public');
    }
    return $out?:[p50_radar_result($profileId,'YouTube','no_recent_content',['canonicalUrl'=>$ref['canonicalUrl'],'source'=>'YouTube Atom'])];
}

function p50_radar_x_adapter(string $profileId,string $url,int $confidence): array {
    global $config;
    $token=trim((string)($config['metrics']['x_bearer_token']??(getenv('PASS50_X_BEARER_TOKEN')?:'')));
    if($token==='')return p50_radar_social_adapter($profileId,'X',$url,$confidence);
    $username=p50m_x_username($url);
    if($username==='')return [p50_radar_result($profileId,'X','invalid_url',['canonicalUrl'=>$url])];
    try{
        $headers=['Authorization: Bearer '.$token];
        $user=p50_radar_json_headers('https://api.x.com/2/users/by/username/'.rawurlencode($username).'?user.fields=public_metrics',$headers);
        $account=$user['data']??null;
        if(!is_array($account))return [p50_radar_result($profileId,'X','temporarily_unavailable',['canonicalUrl'=>$url,'source'=>'API X v2'])];
        $tweets=p50_radar_json_headers('https://api.x.com/2/users/'.rawurlencode((string)$account['id']).'/tweets?'.http_build_query([
            'max_results'=>10,'exclude'=>'retweets,replies','tweet.fields'=>'created_at,public_metrics'
        ]),$headers);
        $out=[];
        foreach((array)($tweets['data']??[]) as $tweet){
            $published=(string)($tweet['created_at']??'');$ts=strtotime($published);
            if($ts===false||$ts<time()-7*86400)continue;
            $id=(string)($tweet['id']??'');if($id==='')continue;$m=(array)($tweet['public_metrics']??[]);
            $out[]=p50_radar_result($profileId,'X','collected',[
                'contentId'=>$id,'canonicalUrl'=>'https://x.com/'.$username.'/status/'.$id,'publishedAt'=>$published,
                'metrics'=>['likes'=>$m['like_count']??null,'comments'=>$m['reply_count']??null,'shares'=>$m['retweet_count']??null,'followers'=>$account['public_metrics']['followers_count']??null],
                'confidence'=>$confidence,'source'=>'API X v2','title'=>(string)($tweet['text']??''),
            ]);
        }
        return $out?:[p50_radar_result($profileId,'X','no_recent_content',['canonicalUrl'=>$url,'source'=>'API X v2'])];
    }catch(Throwable $e){
        $message=$e->getMessage();
        $status=in_array($message,['rate_limited','temporarily_unavailable'],true)?$message:'error';
        return [p50_radar_result($profileId,'X',$status,['canonicalUrl'=>$url,'source'=>'API X v2','error'=>'API X indisponible'])];
    }
}

function p50_radar_rss_items(string $profileId,string $url,int $confidence): array {
    $r=p50_radar_fetch($url,'application/rss+xml,application/atom+xml,application/xml,text/xml');
    if(!$r['ok']||$r['body']==='')return [];
    if(!function_exists('simplexml_load_string'))return [];
    libxml_use_internal_errors(true);$xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if(!$xml)return [];$nodes=$xml->channel->item??$xml->entry??[];$out=[];$seen=[];
    foreach($nodes as $item){
        $link=trim((string)($item->link['href']??$item->link??''));$title=trim((string)($item->title??''));
        $date=(string)($item->pubDate??$item->published??$item->updated??'');$ts=strtotime($date);
        $canonical=p50_de_normalize_activity_url($link);$path=trim((string)(parse_url($canonical,PHP_URL_PATH)?:''),'/');
        if($title===''||!filter_var($link,FILTER_VALIDATE_URL)||$path===''||$ts===false||$ts<time()-7*86400)continue;
        $logical=p50_de_activity_key($profileId,'Web',$canonical);if(isset($seen[$logical]))continue;$seen[$logical]=true;
        $content=p50_radar_content_document($canonical,'Web');
        $status=$content['ok']?(p50_radar_present_metrics((array)$content['metrics'])?'collected':'public_metrics_unavailable'):$content['status'];
        $out[]=p50_radar_result($profileId,'Web',$status,[
            'canonicalUrl'=>$canonical,'publishedAt'=>gmdate('c',$ts),'metrics'=>$content['metrics'],
            'confidence'=>$confidence,'source'=>'Flux RSS public','title'=>$title,'error'=>$content['ok']?'':'Contenu RSS détecté mais inaccessible',
        ]);
    }
    return $out;
}

function p50_radar_web_adapter(string $profileId,string $url,int $confidence): array {
    if(!filter_var($url,FILTER_VALIDATE_URL))return [p50_radar_result($profileId,'Web','invalid_url')];
    $items=p50_radar_rss_items($profileId,$url,$confidence);if($items)return $items;
    $r=p50_radar_fetch($url,'text/html,application/xhtml+xml');
    if(!$r['ok']||$r['body']==='')return [p50_radar_result($profileId,'Web',$r['collectionStatus'],['canonicalUrl'=>$url])];
    preg_match_all('/<link[^>]+(?:type=["\']application\\/(?:rss|atom)\\+xml["\'][^>]+href|href)=["\']([^"\']+)/i',$r['body'],$matches);
    foreach((array)($matches[1]??[]) as $feedUrl){
        $absolute=p50_de_absolute_content_url((string)$feedUrl,$r['finalUrl']?:$url);
        $items=p50_radar_rss_items($profileId,$absolute,$confidence);if($items)return $items;
    }
    $content=p50_radar_content_document($r['finalUrl']?:$url,'Web');
    if(!$content['ok'])return [p50_radar_result($profileId,'Web',$content['status'],['canonicalUrl'=>$content['canonicalUrl'],'source'=>'Open Graph / JSON-LD'])];
    if(!$content['isPublication']||$content['title']===''||empty($content['publishedAt']))return [p50_radar_result($profileId,'Web','no_recent_content',['canonicalUrl'=>$content['canonicalUrl'],'source'=>'Open Graph / JSON-LD'])];
    $ts=strtotime((string)$content['publishedAt']);
    if($ts===false||$ts<time()-7*86400)return [p50_radar_result($profileId,'Web','no_recent_content',['canonicalUrl'=>p50_de_normalize_activity_url($r['finalUrl']?:$url),'source'=>'Open Graph / JSON-LD'])];
    return [p50_radar_result($profileId,'Web',p50_radar_present_metrics((array)$content['metrics'])?'collected':'public_metrics_unavailable',[
        'canonicalUrl'=>$content['canonicalUrl'],'publishedAt'=>$content['publishedAt'],
        'metrics'=>$content['metrics'],'confidence'=>$confidence,'source'=>'Open Graph / JSON-LD','title'=>$content['title'],
    ])];
}

function p50_radar_social_adapter(string $profileId,string $platform,string $url,int $confidence): array {
    if(!filter_var($url,FILTER_VALIDATE_URL))return [p50_radar_result($profileId,$platform,'invalid_url')];
    $r=p50_radar_fetch($url,'text/html,application/xhtml+xml');
    if(!$r['ok']||$r['body']==='')return [p50_radar_result($profileId,$platform,$r['collectionStatus'],['canonicalUrl'=>$url])];
    $urls=p50_de_social_content_urls_from_html($r['body'],$r['finalUrl']?:$url,$platform,5);
    if(!$urls)return [p50_radar_result($profileId,$platform,'public_metrics_unavailable',['canonicalUrl'=>$url,'source'=>'Page publique'])];
    $out=[];
    foreach($urls as $contentUrl){
        $contentId=p50_de_activity_content_id($platform,$contentUrl);
        $content=p50_radar_content_document($contentUrl,$platform);
        if(!$content['ok']){
            $out[]=p50_radar_result($profileId,$platform,$content['status'],['contentId'=>$contentId,'canonicalUrl'=>p50_de_normalize_activity_url($contentUrl),'confidence'=>$confidence,'source'=>'Page publique','accessStatus'=>'detected_content_inaccessible','error'=>'Contenu détecté mais inaccessible']);
            continue;
        }
        $ts=strtotime((string)($content['publishedAt']??''));
        if(($contentId===''&&$content['title']==='')||$ts===false||$ts<time()-7*86400)continue;
        $out[]=p50_radar_result($profileId,$platform,p50_radar_present_metrics((array)$content['metrics'])?'collected':'public_metrics_unavailable',[
            'contentId'=>$contentId,'canonicalUrl'=>p50_de_normalize_activity_url($contentUrl),'publishedAt'=>$content['publishedAt'],
            'metrics'=>$content['metrics'],'confidence'=>$confidence,'source'=>'Page publique','title'=>$content['title'],
        ]);
    }
    return $out?:[p50_radar_result($profileId,$platform,'no_recent_content',['canonicalUrl'=>$url,'source'=>'Page publique'])];
}

function p50_radar_metric_deltas(array $previous,array $current): array {
    $out=[];
    foreach(p50_radar_empty_metrics() as $key=>$_){
        if(!array_key_exists($key,$current)||$current[$key]===null)continue;
        if(!array_key_exists($key,$previous)||$previous[$key]===null){$out[$key]=null;continue;}
        $out[$key]=max(0,(int)$current[$key]-(int)$previous[$key]);
    }
    return $out;
}

function p50_radar_store(array $item): array {
    if($item['collectionStatus']!=='collected')return ['captureRecorded'=>false,'duplicate'=>false,'activeMetrics'=>0];
    $metrics=p50_radar_present_metrics((array)$item['metrics']);
    if(!$metrics)return ['captureRecorded'=>false,'duplicate'=>false,'activeMetrics'=>0];
    $pdo=db();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $key=p50_de_activity_key((string)$item['profileId'],(string)$item['platform'],(string)$item['canonicalUrl'],(string)$item['contentId']);
        p50_de_add_activity((string)$item['profileId'],(string)$item['platform'],'radar',(string)($item['title']?:'Contenu public récent'),(string)$item['canonicalUrl'],$item['publishedAt']?gmdate('Y-m-d H:i:s',strtotime((string)$item['publishedAt'])):null,$metrics,(int)$item['confidence'],(string)$item['contentId']);
        $eventStmt=$pdo->prepare('SELECT id FROM p50_activity_events WHERE profile_id=? AND url_hash=? LIMIT 1 FOR UPDATE');
        $eventStmt->execute([$item['profileId'],$key]);$eventId=(int)($eventStmt->fetchColumn()?:0);
        if($eventId<=0)throw new RuntimeException('Événement Radar introuvable après écriture.');
        $last=$pdo->prepare('SELECT metrics FROM p50_radar_metric_captures WHERE event_id=? ORDER BY captured_at DESC,id DESC LIMIT 1 FOR UPDATE');
        $last->execute([$eventId]);$previous=decode_json_column($last->fetchColumn()?:null,[]);
        if($previous===$metrics){if($ownsTransaction)$pdo->commit();return ['captureRecorded'=>false,'duplicate'=>true,'activeMetrics'=>count($metrics)];}
        $deltas=p50_radar_metric_deltas($previous,$metrics);
        $pdo->prepare('INSERT INTO p50_radar_metric_captures(event_id,profile_id,platform,content_key,content_id,canonical_url,published_at,metrics,metric_deltas,captured_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([$eventId,$item['profileId'],$item['platform'],$key,$item['contentId']?:null,$item['canonicalUrl'],$item['publishedAt']?gmdate('Y-m-d H:i:s',strtotime((string)$item['publishedAt'])):null,json_encode($metrics),json_encode($deltas)]);
        if($ownsTransaction)$pdo->commit();
        return ['captureRecorded'=>true,'duplicate'=>false,'activeMetrics'=>count($metrics),'deltas'=>$deltas,'eventId'=>$eventId];
    }catch(Throwable $e){
        if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function p50_radar_collect_profile(array $profile): array {
    p50_radar_ensure_schema();
    $profileId=(string)$profile['profile_id'];$links=p50_de_social_links($profileId,true);
    $summary=['profileId'=>$profileId,'officialLinksAnalyzed'=>0,'recentPublications'=>0,'capturesRecorded'=>0,'activeMetrics'=>0,'unavailablePlatforms'=>0,'items'=>[]];
    foreach($links as $link){
        $platform=(string)$link['platform'];$url=(string)$link['url'];$confidence=(int)$link['confidence'];$summary['officialLinksAnalyzed']++;
        try{
            $items=match($platform){
                'YouTube'=>p50_radar_youtube_adapter($profileId,$url,$confidence),
                'Web'=>p50_radar_web_adapter($profileId,$url,$confidence),
                'X'=>p50_radar_x_adapter($profileId,$url,$confidence),
                'TikTok','Facebook','Instagram'=>p50_radar_social_adapter($profileId,$platform,$url,$confidence),
                default=>[p50_radar_result($profileId,$platform,'unsupported_url',['canonicalUrl'=>$url])],
            };
        }catch(Throwable $e){
            $items=[p50_radar_result($profileId,$platform,in_array($e->getMessage(),P50_RADAR_STATUSES,true)?$e->getMessage():'error',['canonicalUrl'=>$url,'error'=>'Collecte publique impossible'])];
        }
        $detected=0;$captures=0;$platformUnavailable=false;
        foreach($items as &$item){
            if(!empty($item['publishedAt'])||!empty($item['contentId']))$detected++;
            if(in_array($item['collectionStatus'],['public_metrics_unavailable','rate_limited','temporarily_unavailable','timeout','http_error','content_removed_or_private','detected_content_inaccessible','budget_exceeded'],true))$platformUnavailable=true;
            try{$stored=p50_radar_store($item);}catch(Throwable $e){$stored=['captureRecorded'=>false,'duplicate'=>false,'activeMetrics'=>0];$item['collectionStatus']='error';$item['error']='Écriture Radar impossible';}
            if($stored['duplicate'])$item['collectionStatus']='duplicate';
            $captures+=(int)$stored['captureRecorded'];$summary['activeMetrics']+=(int)$stored['activeMetrics'];
            $item['metricDeltas']=$stored['deltas']??[];
        }
        unset($item);
        if($platformUnavailable)$summary['unavailablePlatforms']++;
        $summary['recentPublications']+=$detected;$summary['capturesRecorded']+=$captures;$summary['items']=array_merge($summary['items'],$items);
        $status=$items[0]['collectionStatus']??'error';
        $metadata=['items'=>count($items)];
        if($platform==='YouTube'){
            $metadata['youtubeApi']=p50_radar_youtube_status();
            $metadata['videos']=array_values(array_map(static fn(array $item): array=>[
                'videoId'=>(string)($item['contentId']??''),
                'channelId'=>(string)($item['channelId']??''),
                'channel'=>(string)($item['channel']??''),
                'subscribers'=>$item['subscribers']??null,
                'publishedAt'=>$item['publishedAt']??null,
            ],array_filter($items,static fn(array $item): bool=>(string)($item['contentId']??'')!=='')));
        }
        db()->prepare('INSERT INTO p50_radar_collection_log(profile_id,platform,official_url,collection_status,publications_detected,captures_recorded,error_message,metadata,collected_at) VALUES(?,?,?,?,?,?,?,?,NOW())')
            ->execute([$profileId,$platform,$url,$status,$detected,$captures,null,json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
    return $summary;
}
