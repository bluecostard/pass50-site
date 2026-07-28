<?php
declare(strict_types=1);

require_once __DIR__.'/metrics-collector-tiktok.php';
require_once __DIR__.'/metrics-collector-instagram.php';
require_once __DIR__.'/metrics-collector-facebook.php';
require_once __DIR__.'/metrics-collector-snapchat.php';

function p50_mc_credentials(string $platform,string $profileId): array {
    global $config;$metrics=(array)($config['metrics']??[]);$perProfile=(array)($metrics['social_credentials'][$platform][$profileId]??[]);
    $read=static function(string $key,string $env='') use($metrics,$perProfile): string {
        $value=$perProfile[$key]??$metrics[$key]??($env!==''?getenv($env):'');return trim((string)$value);
    };
    if($platform==='YouTube')return ['configured'=>p50_mc_config('YouTube')!=='','authorized'=>p50_mc_config('YouTube')!=='','mode'=>p50_mc_config('YouTube')!==''?'official_api':'public_fallback','authorizationRequired'=>false,'secret'=>p50_mc_config('YouTube')];
    if($platform==='X')return ['configured'=>p50_mc_config('X')!=='','authorized'=>p50_mc_config('X')!=='','mode'=>'official_api','authorizationRequired'=>false,'secret'=>p50_mc_config('X')];
    if($platform==='TikTok'){
        $mode=(string)($perProfile['mode']??$metrics['tiktok_mode']??'none');
        $approved=(bool)($perProfile['research_approved']??$metrics['tiktok_research_approved']??false);
        $secret=$mode==='approved_research'?$read('tiktok_research_token','PASS50_TIKTOK_RESEARCH_TOKEN'):$read('tiktok_access_token','PASS50_TIKTOK_ACCESS_TOKEN');
        $configured=$mode==='authorized_display'?true:($mode==='approved_research'&&$approved);
        return ['configured'=>$configured,'authorized'=>$secret!=='','mode'=>$mode,'authorizationRequired'=>$mode==='authorized_display'&&$secret==='','secret'=>$secret,'approved'=>$approved];
    }
    $prefix=strtolower($platform);$secret=$read($prefix.'_access_token','PASS50_'.strtoupper($prefix).'_ACCESS_TOKEN');
    $mode=(string)($perProfile['mode']??$metrics[$prefix.'_mode']??'official_api');
    $configured=(bool)($perProfile['enabled']??$metrics[$prefix.'_enabled']??($secret!==''));
    return ['configured'=>$configured,'authorized'=>$secret!=='','mode'=>$mode,'authorizationRequired'=>$configured&&$secret==='','secret'=>$secret];
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
