<?php
declare(strict_types=1);
require_once __DIR__.'/metrics-core.php';

const P50_RADAR_STATUSES = [
    'collected','no_recent_content','public_metrics_unavailable','invalid_url',
    'unsupported_url','rate_limited','temporarily_unavailable','duplicate','error',
];

function p50_radar_ensure_schema(): void {
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
        profile_id VARCHAR(100) NOT NULL,
        platform VARCHAR(32) NOT NULL,
        content_key CHAR(64) CHARACTER SET ascii NOT NULL,
        content_id VARCHAR(191) NULL,
        canonical_url TEXT NOT NULL,
        published_at DATETIME NULL,
        metrics LONGTEXT NOT NULL,
        metric_deltas LONGTEXT NOT NULL,
        captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_p50_radar_capture_content(profile_id,platform,content_key,captured_at),
        INDEX idx_p50_radar_capture_date(captured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
        'title'=>(string)($values['title']??''),
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
    global $config;
    return trim((string)($config['metrics']['youtube_api_key']??(getenv('PASS50_YOUTUBE_API_KEY')?:'')));
}

function p50_radar_json(string $url): array {
    $r=p50_http_fetch($url,10,'application/json');
    if((int)$r['status']===429)throw new RuntimeException('rate_limited');
    if(!$r['ok']||$r['body']==='')throw new RuntimeException((int)$r['status']>=500?'temporarily_unavailable':'error');
    $data=json_decode($r['body'],true);
    if(!is_array($data))throw new RuntimeException('error');
    return $data;
}

function p50_radar_json_headers(string $url,array $headers): array {
    if(!p50_public_http_url($url))throw new RuntimeException('error');
    $ch=curl_init($url);
    if($ch===false)throw new RuntimeException('temporarily_unavailable');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_USERAGENT=>'PASS50-Radar/1.0',
        CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'],$headers),
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($status===429)throw new RuntimeException('rate_limited');
    if($body===false||$status<200||$status>=300)throw new RuntimeException($status>=500?'temporarily_unavailable':'error');
    $data=json_decode((string)$body,true);
    if(!is_array($data))throw new RuntimeException('error');
    return $data;
}

function p50_radar_youtube_video(string $profileId,string $videoId,int $confidence,string $source='YouTube public'): array {
    $url='https://youtube.com/watch?v='.$videoId;$metrics=[];$published=null;$title='';
    $key=p50_radar_youtube_key();
    if($key!==''){
        $data=p50_radar_json('https://www.googleapis.com/youtube/v3/videos?'.http_build_query(['part'=>'snippet,statistics','id'=>$videoId,'key'=>$key]));
        $video=$data['items'][0]??null;
        if(!is_array($video))return p50_radar_result($profileId,'YouTube','temporarily_unavailable',['canonicalUrl'=>$url,'contentId'=>$videoId,'source'=>'YouTube Data API']);
        $stats=(array)($video['statistics']??[]);
        $metrics=['views'=>$stats['viewCount']??null,'likes'=>$stats['likeCount']??null,'comments'=>$stats['commentCount']??null];
        $published=(string)($video['snippet']['publishedAt']??'')?:null;$title=(string)($video['snippet']['title']??'');
        $source='YouTube Data API';
    }else{
        $content=p50_de_content_metrics($url);$metrics=(array)$content['metrics'];$published=$content['publishedAt'];$title=(string)$content['title'];
    }
    $status=p50_radar_present_metrics($metrics)?'collected':'public_metrics_unavailable';
    return p50_radar_result($profileId,'YouTube',$status,['contentId'=>$videoId,'canonicalUrl'=>$url,'publishedAt'=>$published,'metrics'=>$metrics,'confidence'=>$confidence,'source'=>$source,'title'=>$title]);
}

function p50_radar_youtube_adapter(string $profileId,string $url,int $confidence): array {
    $ref=p50_radar_youtube_reference($url);
    if($ref['kind']==='invalid')return [p50_radar_result($profileId,'YouTube','invalid_url',['canonicalUrl'=>$ref['canonicalUrl']])];
    if(in_array($ref['kind'],['video','short'],true))return [p50_radar_youtube_video($profileId,$ref['id'],$confidence)];
    $channelId=$ref['kind']==='channel'?$ref['id']:p50_de_youtube_channel_id($ref['canonicalUrl']);
    if($channelId==='')return [p50_radar_result($profileId,'YouTube','unsupported_url',['canonicalUrl'=>$ref['canonicalUrl']])];
    $feed=p50_http_fetch('https://www.youtube.com/feeds/videos.xml?channel_id='.rawurlencode($channelId),10,'application/atom+xml,application/xml');
    if((int)$feed['status']===429)return [p50_radar_result($profileId,'YouTube','rate_limited',['canonicalUrl'=>$ref['canonicalUrl']])];
    if(!$feed['ok']||$feed['body']==='')return [p50_radar_result($profileId,'YouTube','temporarily_unavailable',['canonicalUrl'=>$ref['canonicalUrl']])];
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
    $r=p50_http_fetch($url,10,'application/rss+xml,application/atom+xml,application/xml,text/xml');
    if(!$r['ok']||$r['body']==='')return [];
    if(!function_exists('simplexml_load_string'))return [];
    libxml_use_internal_errors(true);$xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if(!$xml)return [];$nodes=$xml->channel->item??$xml->entry??[];$out=[];
    foreach($nodes as $item){
        $link=trim((string)($item->link['href']??$item->link??''));$title=trim((string)($item->title??''));
        $date=(string)($item->pubDate??$item->published??$item->updated??'');$ts=strtotime($date);
        if(!filter_var($link,FILTER_VALIDATE_URL)||$ts===false||$ts<time()-7*86400)continue;
        $content=p50_de_content_metrics($link);
        $out[]=p50_radar_result($profileId,'Web',p50_radar_present_metrics((array)$content['metrics'])?'collected':'public_metrics_unavailable',[
            'canonicalUrl'=>p50_de_normalize_activity_url($link),'publishedAt'=>gmdate('c',$ts),'metrics'=>$content['metrics'],
            'confidence'=>$confidence,'source'=>'Flux RSS public','title'=>$title?:$content['title'],
        ]);
    }
    return $out;
}

function p50_radar_web_adapter(string $profileId,string $url,int $confidence): array {
    if(!filter_var($url,FILTER_VALIDATE_URL))return [p50_radar_result($profileId,'Web','invalid_url')];
    $items=p50_radar_rss_items($profileId,$url,$confidence);if($items)return $items;
    $r=p50_http_fetch($url,10,'text/html,application/xhtml+xml');
    if((int)$r['status']===429)return [p50_radar_result($profileId,'Web','rate_limited',['canonicalUrl'=>$url])];
    if(!$r['ok']||$r['body']==='')return [p50_radar_result($profileId,'Web','temporarily_unavailable',['canonicalUrl'=>$url])];
    preg_match_all('/<link[^>]+(?:type=["\']application\\/(?:rss|atom)\\+xml["\'][^>]+href|href)=["\']([^"\']+)/i',$r['body'],$matches);
    foreach((array)($matches[1]??[]) as $feedUrl){
        $absolute=p50_de_absolute_content_url((string)$feedUrl,$r['finalUrl']?:$url);
        $items=p50_radar_rss_items($profileId,$absolute,$confidence);if($items)return $items;
    }
    $content=p50_de_content_metrics($r['finalUrl']?:$url);
    if(empty($content['publishedAt']))return [p50_radar_result($profileId,'Web','no_recent_content',['canonicalUrl'=>p50_de_normalize_activity_url($r['finalUrl']?:$url),'source'=>'Open Graph / JSON-LD'])];
    $ts=strtotime((string)$content['publishedAt']);
    if($ts===false||$ts<time()-7*86400)return [p50_radar_result($profileId,'Web','no_recent_content',['canonicalUrl'=>p50_de_normalize_activity_url($r['finalUrl']?:$url),'source'=>'Open Graph / JSON-LD'])];
    return [p50_radar_result($profileId,'Web',p50_radar_present_metrics((array)$content['metrics'])?'collected':'public_metrics_unavailable',[
        'canonicalUrl'=>p50_de_normalize_activity_url($r['finalUrl']?:$url),'publishedAt'=>$content['publishedAt'],
        'metrics'=>$content['metrics'],'confidence'=>$confidence,'source'=>'Open Graph / JSON-LD','title'=>$content['title'],
    ])];
}

function p50_radar_social_adapter(string $profileId,string $platform,string $url,int $confidence): array {
    if(!filter_var($url,FILTER_VALIDATE_URL))return [p50_radar_result($profileId,$platform,'invalid_url')];
    $r=p50_http_fetch($url,10,'text/html,application/xhtml+xml');
    if((int)$r['status']===429)return [p50_radar_result($profileId,$platform,'rate_limited',['canonicalUrl'=>$url])];
    if(!$r['ok']||$r['body']==='')return [p50_radar_result($profileId,$platform,'temporarily_unavailable',['canonicalUrl'=>$url])];
    $urls=p50_de_social_content_urls_from_html($r['body'],$r['finalUrl']?:$url,$platform,5);
    if(!$urls)return [p50_radar_result($profileId,$platform,'public_metrics_unavailable',['canonicalUrl'=>$url,'source'=>'Page publique'])];
    $out=[];
    foreach($urls as $contentUrl){
        $content=p50_de_content_metrics($contentUrl);$ts=strtotime((string)($content['publishedAt']??''));
        if($ts===false||$ts<time()-7*86400)continue;
        $contentId=p50_de_activity_content_id($platform,$contentUrl);
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
    $key=p50_de_activity_key((string)$item['profileId'],(string)$item['platform'],(string)$item['canonicalUrl'],(string)$item['contentId']);
    $last=db()->prepare('SELECT metrics FROM p50_radar_metric_captures WHERE profile_id=? AND platform=? AND content_key=? ORDER BY captured_at DESC,id DESC LIMIT 1');
    $last->execute([$item['profileId'],$item['platform'],$key]);$previous=decode_json_column($last->fetchColumn()?:null,[]);
    if($previous===$metrics)return ['captureRecorded'=>false,'duplicate'=>true,'activeMetrics'=>count($metrics)];
    $deltas=p50_radar_metric_deltas($previous,$metrics);
    db()->prepare('INSERT INTO p50_radar_metric_captures(profile_id,platform,content_key,content_id,canonical_url,published_at,metrics,metric_deltas,captured_at) VALUES(?,?,?,?,?,?,?,?,NOW())')
        ->execute([$item['profileId'],$item['platform'],$key,$item['contentId']?:null,$item['canonicalUrl'],$item['publishedAt']?gmdate('Y-m-d H:i:s',strtotime((string)$item['publishedAt'])):null,json_encode($metrics),json_encode($deltas)]);
    p50_de_add_activity((string)$item['profileId'],(string)$item['platform'],'radar',(string)($item['title']?:'Contenu public récent'),(string)$item['canonicalUrl'],$item['publishedAt']?gmdate('Y-m-d H:i:s',strtotime((string)$item['publishedAt'])):null,$metrics,(int)$item['confidence'],(string)$item['contentId']);
    return ['captureRecorded'=>true,'duplicate'=>false,'activeMetrics'=>count($metrics),'deltas'=>$deltas];
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
            if(in_array($item['collectionStatus'],['public_metrics_unavailable','rate_limited','temporarily_unavailable'],true))$platformUnavailable=true;
            $stored=p50_radar_store($item);
            if($stored['duplicate'])$item['collectionStatus']='duplicate';
            $captures+=(int)$stored['captureRecorded'];$summary['activeMetrics']+=(int)$stored['activeMetrics'];
            $item['metricDeltas']=$stored['deltas']??[];
        }
        unset($item);
        if($platformUnavailable)$summary['unavailablePlatforms']++;
        $summary['recentPublications']+=$detected;$summary['capturesRecorded']+=$captures;$summary['items']=array_merge($summary['items'],$items);
        $status=$items[0]['collectionStatus']??'error';
        db()->prepare('INSERT INTO p50_radar_collection_log(profile_id,platform,official_url,collection_status,publications_detected,captures_recorded,error_message,metadata,collected_at) VALUES(?,?,?,?,?,?,?,?,NOW())')
            ->execute([$profileId,$platform,$url,$status,$detected,$captures,null,json_encode(['items'=>count($items)])]);
    }
    return $summary;
}
