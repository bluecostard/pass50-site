<?php
declare(strict_types=1);

require_once __DIR__.'/tiktok-metrics-bridge-core.php';
require_once __DIR__.'/metrics-collector-tiktok.php';
require_once __DIR__.'/metrics-collector-instagram.php';
require_once __DIR__.'/metrics-collector-facebook.php';
require_once __DIR__.'/metrics-collector-snapchat.php';

function p50_mc_env_bool(string $name,bool $fallback=false): bool {
    $value=getenv($name);if($value===false||trim((string)$value)==='')return $fallback;
    return filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)??$fallback;
}

/** Kill switch plateforme : false explicite coupe même OAuth / tokens présents. */
function p50_mc_platform_enabled(string $platform): bool {
    global $config;$metrics=(array)($config['metrics']??[]);$prefix=strtolower($platform);
    if($prefix==='')return true;
    $key=$prefix.'_enabled';
    if(array_key_exists($key,$metrics))return (bool)filter_var($metrics[$key],FILTER_VALIDATE_BOOLEAN);
    $env='PASS50_'.strtoupper($prefix).'_ENABLED';
    $raw=getenv($env);
    if($raw!==false&&trim((string)$raw)!=='')return (bool)filter_var($raw,FILTER_VALIDATE_BOOLEAN);
    return true;
}

function p50_mc_credentials(string $platform,string $profileId): array {
    global $config;$metrics=(array)($config['metrics']??[]);$perProfile=(array)($metrics['social_credentials'][$platform][$profileId]??[]);
    if(!p50_mc_platform_enabled($platform)){
        return ['configured'=>false,'authorized'=>false,'mode'=>'disabled','authorizationRequired'=>false,'secret'=>'',
            'accountId'=>'','pageId'=>'','discoveryAccountId'=>'','storiesAuthorized'=>false,'insightsAuthorized'=>false,
            'graphVersion'=>'','disabled'=>true];
    }
    $read=static function(string $key,string $env='') use($metrics,$perProfile): string {
        foreach([$perProfile[$key]??null,$metrics[$key]??null,$env!==''?getenv($env):null] as $candidate){
            $value=trim((string)($candidate??''));if($value!=='')return $value;
        }
        return '';
    };
    if($platform==='YouTube'){
        $oauth=function_exists('p50ym_public_access')?p50ym_public_access($profileId):['configured'=>false,'authorized'=>false,'mode'=>'mapping_required','authorizationRequired'=>true];
        if(!empty($oauth['configured']))return $oauth+['secret'=>''];
        $api=p50_mc_config('YouTube');
        return ['configured'=>$api!=='','authorized'=>$api!=='','mode'=>$api!==''?'official_api':'public_fallback','authorizationRequired'=>false,'secret'=>$api];
    }
    if($platform==='X'){
        $secret=p50_mc_config('X');if($secret==='')$secret=trim((string)(getenv('PASS50_X_BEARER_TOKEN')?:''));
        return ['configured'=>$secret!=='','authorized'=>$secret!=='','mode'=>'official_api','authorizationRequired'=>false,'secret'=>$secret];
    }
    if($platform==='TikTok'){
        $oauth=function_exists('p50tm_public_access')?p50tm_public_access($profileId):['configured'=>false,'authorized'=>false,'mode'=>'mapping_required','authorizationRequired'=>true];
        if(!empty($oauth['configured']))return $oauth+['secret'=>''];
        $displayToken=$read('tiktok_access_token','PASS50_TIKTOK_ACCESS_TOKEN');
        $researchToken=$read('tiktok_research_token','PASS50_TIKTOK_RESEARCH_TOKEN');
        $approved=(bool)($perProfile['research_approved']??$metrics['tiktok_research_approved']??false)||p50_mc_env_bool('PASS50_TIKTOK_RESEARCH_APPROVED',false);
        $mode=trim((string)($perProfile['mode']??$metrics['tiktok_mode']??'none'))?:'none';
        $envMode=trim((string)(getenv('PASS50_TIKTOK_MODE')?:''));if($mode==='none'&&$envMode!=='')$mode=$envMode;
        if($mode==='none'&&$researchToken!==''&&$approved)$mode='approved_research';
        $secret=$mode==='approved_research'?$researchToken:$displayToken;
        $configured=$mode==='authorized_display'?true:($mode==='approved_research'&&$approved);
        return ['configured'=>$configured,'authorized'=>$secret!=='','mode'=>$mode,'authorizationRequired'=>$configured&&$secret==='','secret'=>$secret,'approved'=>$approved];
    }
    if(in_array($platform,['Facebook','Instagram'],true)&&function_exists('p50mm_credentials')){
        $oauth=p50mm_credentials($platform,$profileId);
        if(is_array($oauth))return $oauth;
    }
    $prefix=strtolower($platform);$secret=$read($prefix.'_access_token','PASS50_'.strtoupper($prefix).'_ACCESS_TOKEN');
    $accountId=$read($prefix.'_account_id','PASS50_'.strtoupper($prefix).'_ACCOUNT_ID');
    $pageId=$read('facebook_page_id','PASS50_FACEBOOK_PAGE_ID');
    $discoveryAccountId=$read('instagram_discovery_account_id','PASS50_INSTAGRAM_DISCOVERY_ACCOUNT_ID');
    $mode=(string)($perProfile['mode']??$metrics[$prefix.'_mode']??'official_api');
    if($platform==='Instagram'&&$mode==='professional_authorized'&&$accountId===''&&$discoveryAccountId!=='')$mode='business_discovery';
    $explicitEnabled=$perProfile['enabled']??$metrics[$prefix.'_enabled']??null;
    $configured=$secret!==''||(bool)$explicitEnabled;
    $graphVersion=trim((string)($perProfile['graph_version']??$metrics['meta_graph_version']??$config['meta_oauth']['graph_version']??''));
    if($graphVersion==='')$graphVersion=trim((string)(getenv('META_GRAPH_VERSION')?:'v22.0'))?:'v22.0';
    return ['configured'=>$configured,'authorized'=>$secret!=='','mode'=>$mode,'authorizationRequired'=>$configured&&$secret==='','secret'=>$secret,
      'accountId'=>$accountId,'pageId'=>$pageId,'discoveryAccountId'=>$discoveryAccountId,
      'storiesAuthorized'=>(bool)($perProfile['stories_authorized']??$metrics[$prefix.'_stories_authorized']??false),
      'insightsAuthorized'=>(bool)($perProfile['insights_authorized']??$metrics[$prefix.'_insights_authorized']??true),
      'graphVersion'=>$graphVersion];
}

function p50_mc_public_access(string $platform,string $profileId): array {
    $credentials=p50_mc_credentials($platform,$profileId);
    return ['configured'=>(bool)$credentials['configured'],'authorized'=>(bool)$credentials['authorized'],'mode'=>(string)$credentials['mode'],'authorizationRequired'=>(bool)$credentials['authorizationRequired']];
}

function p50_mc_dispatch(string $platform): callable {
    return match($platform){'YouTube'=>'p50_mc_youtube','X'=>'p50_mc_x','TikTok'=>'p50_mc_tiktok','Instagram'=>'p50_mc_instagram','Facebook'=>'p50_mc_facebook','Snapchat'=>'p50_mc_snapchat',default=>throw new InvalidArgumentException('Plateforme non prise en charge.')};
}

function p50_msc_username(string $platform,string $url): string {
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));$path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
    if($platform==='TikTok'&&preg_match('#^@([A-Za-z0-9._-]{2,32})$#',$path,$m)&&str_contains($host,'tiktok.com'))return strtolower($m[1]);
    if($platform==='Instagram'&&preg_match('#^([A-Za-z0-9._]{1,30})$#',$path,$m)&&str_contains($host,'instagram.com')&&!in_array(strtolower($m[1]),['explore','accounts','login'],true))return strtolower($m[1]);
    if($platform==='Snapchat'&&preg_match('#^(?:add/)?([A-Za-z][A-Za-z0-9._-]{2,30})$#',$path,$m)&&str_contains($host,'snapchat.com'))return strtolower($m[1]);
    return '';
}

function p50_msc_facebook_identity(string $url): array {
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));$path=trim((string)(parse_url($url,PHP_URL_PATH)?:''),'/');
    if(!str_contains($host,'facebook.com')||$path===''||preg_match('#^(?:login|search|share|sharer)(?:/|$)#i',$path))return ['',''];
    if(preg_match('/^pages\/[^\/]+\/(\d+)$/',$path,$m)||preg_match('/^profile\.php$/',$path)&&preg_match('/(?:^|&)id=(\d+)/',(string)parse_url($url,PHP_URL_QUERY),$m))return ['id',$m[1]];
    return preg_match('/^([A-Za-z0-9.]{3,80})$/',$path,$m)?['username',strtolower($m[1])]:['',''];
}

function p50_msc_access_or_status(array $credentials,array &$result): bool {
    if(!$credentials['configured']){$result['status']='configuration_missing';return false;}
    if(!$credentials['authorized']){$result['status']='authorization_required';return false;}
    return true;
}

function p50_msc_response(array $response,string $endpoint,array &$result,bool $accountFound=false): bool {
    $status=(int)($response['status']??0);if($status>=200&&$status<300)return true;
    $result['errors'][]=$endpoint.' '.($status===429?'rate_limited':($status===403?'forbidden':'http_error'));
    if($status===429){$result['rateLimited']=true;$result['status']='rate_limited';}
    elseif(in_array($status,[401,403],true))$result['status']='authorization_required';
    elseif($status===404)$result['status']='unavailable_or_blocked';
    else $result['status']=$accountFound?'partial':'error';
    return false;
}

function p50_msc_time($value): ?string {
    if($value===null||$value==='')return null;
    if(is_int($value)||(is_string($value)&&preg_match('/^\d{10}$/',$value)))return gmdate('c',(int)$value);
    return (string)$value;
}

function p50_msc_query_url(string $base,array $query): string {
    return $base.(str_contains($base,'?')?'&':'?').http_build_query($query);
}

function p50_msc_graph_root(array $credentials): string {
    $version=trim((string)($credentials['graphVersion']??'v22.0'));
    if(!preg_match('/^v\d+\.\d+$/',$version))$version='v22.0';
    return 'https://graph.facebook.com/'.$version.'/';
}

function p50_msc_graph_insights(array $payload): array {
    $metrics=[];
    foreach((array)($payload['data']??[]) as $item){
        $name=(string)($item['name']??'');$values=(array)($item['values']??[]);
        $value=$item['value']??($values[0]['value']??($item['total_value']['value']??null));
        if($name!==''&&$value!==null)$metrics[$name]=$value;
    }
    return $metrics;
}

function p50_msc_snap_assets(array $payload,string $key): array {
    if(isset($payload[$key])&&is_array($payload[$key]))return array_is_list($payload[$key])?$payload[$key]:(array)($payload[$key]['data']??[]);
    if(isset($payload['data'][$key])&&is_array($payload['data'][$key]))return array_is_list($payload['data'][$key])?$payload['data'][$key]:(array)($payload['data'][$key]['data']??[]);
    return [];
}

function p50_msc_snap_stats(array $payload): array {
    $out=[];
    foreach((array)($payload['assets']??[]) as $asset)foreach((array)($asset['timeseries']??[]) as $series){
        foreach((array)($series['stats']??$series) as $name=>$value){
            if(is_array($value)){
                $metric=(string)($value['metric']??$value['name']??$value['field']??'');$metricValue=$value['value']??null;
                if($metric!==''&&is_scalar($metricValue))$out[strtoupper($metric)]=$metricValue;
            }elseif(is_string($name)&&is_scalar($value))$out[strtoupper($name)]=$value;
        }
    }
    return $out;
}

function p50_msc_store_account(PDO $pdo,array $official,string $platform,array $data,string $source,string $endpoint,string $mode,string $observedAt,string $runUuid,array &$result,array $future=[],int $httpStatus=200,?string $rawPayloadHash=null): array {
    $prov=p50_mc_provenance($platform,$source,$endpoint,$official,gmdate('c'),$httpStatus,$runUuid,$mode);
    $account=p50_metrics_upsert_account($pdo,['profileId'=>$official['profile_id'],'platform'=>$platform,'platformAccountId'=>($data['id']??null)?:null,'handle'=>$data['username']??null,'canonicalUrl'=>$official['normalized_url'],'confidence'=>$official['confidence'],'sourceType'=>$source,'observedAt'=>$observedAt,'provenance'=>$prov]);$result['accountFound']=true;
    [$future,$invalid]=p50_mc_future_metrics($future);$followers=p50_mc_int($data,'followers');
    if($followers===null)$result['unavailableMetrics']++;
    if($followers!==null||array_filter($future,static fn($v)=>$v!==null))p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'collector'=>strtolower($platform).'_v1','sourceType'=>$source,'sourceReference'=>$official['normalized_url'],'observedAt'=>$observedAt,'followers'=>$followers,'metrics'=>$future,'qualityStatus'=>$invalid?'quarantined':'usable','confidence'=>$official['confidence'],'runUuid'=>$runUuid,'rawPayloadHash'=>$rawPayloadHash,'provenance'=>$prov]);
    return $account+['provenance'=>$prov];
}

function p50_msc_store_content(PDO $pdo,array $official,array $account,string $platform,array $item,string $source,string $endpoint,string $mode,string $observedAt,string $runUuid,array &$result,array $canonical,array $future=[],int $httpStatus=200,?string $rawPayloadHash=null): void {
    $id=trim((string)($item['id']??''));$url=trim((string)($item['url']??''));if($id===''&&$url==='')return;
    $prov=p50_mc_provenance($platform,$source,$endpoint,$official,gmdate('c'),$httpStatus,$runUuid,$mode);
    $content=p50_metrics_upsert_content($pdo,['accountId'=>$account['id'],'platformContentId'=>$id?:null,'contentType'=>$item['type']??'unknown','canonicalUrl'=>$url,'title'=>$item['title']??'','publishedAt'=>$item['publishedAt']??null,'status'=>$item['status']??'active','confidence'=>$official['confidence'],'sourceType'=>$source,'observedAt'=>$observedAt,'metadata'=>$item['metadata']??[],'provenance'=>$prov]);$result['contentsFound']++;
    $available=false;foreach($canonical as $value)if($value!==null){$available=true;break;}[$future,$invalid]=p50_mc_future_metrics($future);
    if(!$available&&!array_filter($future,static fn($v)=>$v!==null)){$result['unavailableMetrics']++;return;}
    p50_mc_capture($pdo,$result,['accountId'=>$account['id'],'contentId'=>$content['id'],'collector'=>strtolower($platform).'_v1','sourceType'=>$source,'sourceReference'=>$url,'observedAt'=>$observedAt]+$canonical+['metrics'=>$future,'qualityStatus'=>$invalid?'quarantined':'usable','confidence'=>$official['confidence'],'runUuid'=>$runUuid,'rawPayloadHash'=>$rawPayloadHash,'provenance'=>$prov]);
}
