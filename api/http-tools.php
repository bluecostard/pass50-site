<?php
declare(strict_types=1);

/** Outils réseau gratuits PASS50. Aucune clé payante requise. */
function p50_public_http_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) return false;
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || in_array($host, ['localhost','127.0.0.1','::1'], true)) return false;
    $ips = gethostbynamel($host) ?: [];
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

function p50_network_begin_cycle(int $batchLimit=20,int $profileLimit=5,int $timeout=4): void {
    $GLOBALS['p50_network_cycle']=[
        'active'=>true,'batchLimit'=>max(1,$batchLimit),'profileLimit'=>max(1,$profileLimit),
        'timeout'=>max(1,min(8,$timeout)),'used'=>0,'profileUsed'=>0,'cache'=>[],
        'youtubeReservations'=>0,'profileYoutubeReservation'=>false,
    ];
}

function p50_network_begin_profile(): void {
    if(isset($GLOBALS['p50_network_cycle'])){
        $GLOBALS['p50_network_cycle']['profileUsed']=0;
        $GLOBALS['p50_network_cycle']['profileYoutubeReservation']=$GLOBALS['p50_network_cycle']['youtubeReservations']>0;
    }
}

function p50_network_reserve_youtube(int $profiles): void {
    if(isset($GLOBALS['p50_network_cycle']))$GLOBALS['p50_network_cycle']['youtubeReservations']=max(0,min($profiles,(int)$GLOBALS['p50_network_cycle']['batchLimit']));
}

function p50_network_release_youtube_profile(): void {
    if(!isset($GLOBALS['p50_network_cycle'])||empty($GLOBALS['p50_network_cycle']['profileYoutubeReservation']))return;
    $GLOBALS['p50_network_cycle']['youtubeReservations']=max(0,(int)$GLOBALS['p50_network_cycle']['youtubeReservations']-1);
    $GLOBALS['p50_network_cycle']['profileYoutubeReservation']=false;
}

function p50_network_stats(): array {
    $cycle=$GLOBALS['p50_network_cycle']??[];
    $limit=(int)($cycle['batchLimit']??0);$used=(int)($cycle['used']??0);
    return ['limit'=>$limit,'used'=>$used,'remaining'=>max(0,$limit-$used),'status'=>$limit>0&&$used>=$limit?'budget_exceeded':'available'];
}

function p50_network_cache_key(string $url): string {
    $parts=parse_url(trim($url));if(!$parts||empty($parts['host']))return trim($url);
    $query=[];parse_str((string)($parts['query']??''),$raw);
    foreach($raw as $key=>$value){
        if(strcasecmp((string)$key,'key')===0)continue;
        if(preg_match('/^(utm_|fbclid$|gclid$|ref$|source$|feature$|si$|is_from_webapp$|sender_device$|web_id$)/i',(string)$key))continue;
        $query[(string)$key]=$value;
    }
    ksort($query);$host=strtolower((string)$parts['host']);if(str_starts_with($host,'www.'))$host=substr($host,4);
    $path=preg_replace('#/+#','/',(string)($parts['path']??'/'))?:'/';$path=$path==='/'?'/':rtrim($path,'/');
    return strtolower((string)($parts['scheme']??'https')).'://'.$host.$path.($query?'?'.http_build_query($query):'');
}

function p50_network_failure_status(int $status,string $error): string {
    $error=strtolower($error);
    if($status===429)return 'rate_limited';
    if(in_array($status,[401,403,404,410],true))return 'content_removed_or_private';
    if($status>=500)return 'temporarily_unavailable';
    if($status>=400)return 'http_error';
    if($status===0&&(str_contains($error,'timed out')||str_contains($error,'timeout')))return 'timeout';
    return $status===0?'temporarily_unavailable':'http_error';
}

function p50_http_fetch(string $url, int $timeout = 15, string $accept = 'application/json,text/html;q=0.9,*/*;q=0.6', bool $head = false, array $extraHeaders=[]): array {
    if (!p50_public_http_url($url)) return ['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>$url,'contentType'=>'','error'=>'URL distante refusée','collectionStatus'=>'invalid_url','cached'=>false];
    if(isset($GLOBALS['p50_network_cycle'])&&is_array($GLOBALS['p50_network_cycle'])){$cycle=&$GLOBALS['p50_network_cycle'];$active=!empty($cycle['active']);}
    else{$cycle=[];$active=false;}
    if($active)$head=false;
    $cacheKey=p50_network_cache_key($url);
    if($active&&isset($cycle['cache'][$cacheKey]))return $cycle['cache'][$cacheKey]+['cached'=>true];
    $isYoutubeApi=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''))==='www.googleapis.com'
        &&str_starts_with((string)(parse_url($url,PHP_URL_PATH)?:''),'/youtube/v3/');
    $remaining=$active?(int)$cycle['batchLimit']-(int)$cycle['used']:0;
    if($active&&!$isYoutubeApi&&$remaining<=(int)($cycle['youtubeReservations']??0)){
        return ['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>$url,'contentType'=>'','error'=>'budget_exceeded','collectionStatus'=>'budget_exceeded','cached'=>false];
    }
    if($active&&($cycle['used']>=$cycle['batchLimit']||$cycle['profileUsed']>=$cycle['profileLimit'])){
        return ['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>$url,'contentType'=>'','error'=>'budget_exceeded','collectionStatus'=>'budget_exceeded','cached'=>false];
    }
    $effectiveTimeout=$active?min($timeout,(int)$cycle['timeout']):$timeout;$attempt=0;
    do{
        if($active){
            $cycle['used']++;$cycle['profileUsed']++;
            if($isYoutubeApi&&!empty($cycle['profileYoutubeReservation'])){
                $cycle['youtubeReservations']=max(0,(int)$cycle['youtubeReservations']-1);
                $cycle['profileYoutubeReservation']=false;
            }
        }
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,CURLOPT_FOLLOWLOCATION => true,CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $effectiveTimeout,CURLOPT_CONNECTTIMEOUT => min(4,$effectiveTimeout),
            CURLOPT_USERAGENT => 'PASS50-FreeTools/9.0 (+https://pass50.store)',
            CURLOPT_HTTPHEADER => array_merge(['Accept: '.$accept,'Accept-Language: fr-FR,fr;q=0.9,en;q=0.7'],$extraHeaders),
            CURLOPT_NOBODY => $head,CURLOPT_HEADER => false,
        ]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $finalUrl=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);$contentType=strtolower((string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE));
        $error=curl_error($ch);curl_close($ch);$failure=p50_network_failure_status($status,$error);
        $temporary=in_array($failure,['timeout','temporarily_unavailable'],true);
    }while($active&&$temporary&&$attempt<2&&$cycle['used']<$cycle['batchLimit']&&$cycle['profileUsed']<$cycle['profileLimit']);
    $result=['ok'=>is_string($body)&&$status>=200&&$status<400,'status'=>$status,'body'=>is_string($body)?$body:'',
        'finalUrl'=>$finalUrl?:$url,'contentType'=>$contentType,'error'=>$error,
        'collectionStatus'=>is_string($body)&&$status>=200&&$status<400?'collected':$failure,'cached'=>false];
    if($active)$cycle['cache'][$cacheKey]=$result;
    return $result;
}

function p50_json_get(string $url, int $timeout = 15): array {
    $r = p50_http_fetch($url, $timeout, 'application/json,*/*;q=0.7');
    if (!$r['ok']) return [];
    $data = json_decode($r['body'], true);
    return is_array($data) ? $data : [];
}

function p50_meta(string $html, string $name): string {
    $quoted = preg_quote($name, '/');
    $patterns = [
        '/<meta[^>]+(?:property|name)=["\']'.$quoted.'["\'][^>]+content=["\']([^"\']+)["\']/i',
        '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']'.$quoted.'["\']/i',
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern, $html, $m)) return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return '';
}

function p50_page_metadata(string $html, string $baseUrl): array {
    $title = p50_meta($html, 'og:title');
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $title = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $description = p50_meta($html, 'og:description') ?: p50_meta($html, 'description');
    $image = p50_meta($html, 'og:image:secure_url') ?: p50_meta($html, 'og:image') ?: p50_meta($html, 'twitter:image');
    $canonical = '';
    if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m) || preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $html, $m)) $canonical = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (['image','canonical'] as $field) {
        $value = ${$field};
        if ($value !== '' && !preg_match('#^https?://#i', $value)) {
            $parts = parse_url($baseUrl);
            $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            ${$field} = $origin . '/' . ltrim($value, '/');
        }
    }
    return compact('title','description','image','canonical');
}

function p50_platform(string $url): string {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) return 'YouTube';
    if (str_contains($host, 'tiktok.com')) return 'TikTok';
    if (str_contains($host, 'instagram.com')) return 'Instagram';
    if (str_contains($host, 'facebook.com') || str_contains($host, 'fb.watch')) return 'Facebook';
    if ($host === 'x.com' || str_ends_with($host, '.x.com') || str_contains($host, 'twitter.com')) return 'X';
    if (str_contains($host, 'linkedin.com')) return 'LinkedIn';
    if (str_contains($host, 'snapchat.com')) return 'Snapchat';
    return 'Web';
}

function p50_platform_host_ok(string $platform, string $url): bool {
    $detected = p50_platform($url);
    return $platform === 'Web' || strcasecmp($platform, $detected) === 0;
}

function p50_normalize_text(string $value): string {
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted)) $value = $converted;
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
    return trim(preg_replace('/\s+/', ' ', $value) ?: '');
}

function p50_name_score(string $haystack, string $name, string $handle = ''): int {
    $h = p50_normalize_text($haystack);
    $n = p50_normalize_text($name);
    $score = 0;
    if ($n !== '' && str_contains($h, $n)) $score += 65;
    $tokens = array_values(array_filter(explode(' ', $n), static fn($t) => strlen($t) >= 3));
    $matched = 0;
    foreach ($tokens as $token) if (str_contains($h, $token)) $matched++;
    if ($matched >= 2) $score = max($score, 52);
    elseif ($matched === 1) $score = max($score, 22);
    $hn = p50_normalize_text(ltrim($handle, '@'));
    if ($hn !== '' && strlen($hn) >= 4 && str_contains($h, $hn)) $score += 25;
    return min(100, $score);
}
