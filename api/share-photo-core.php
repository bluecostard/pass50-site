<?php
declare(strict_types=1);

const P50_SHARE_PHOTO_MAX_BYTES = 5242880;
const P50_SHARE_PHOTO_CACHE_SECONDS = 21600;

function p50_share_photo_config(): ?array {
    static $loaded=false;
    static $config=null;
    if($loaded)return is_array($config)?$config:null;
    $loaded=true;
    $file=__DIR__.'/config.php';
    if(!is_file($file))return null;
    try{
        $value=require $file;
        $config=is_array($value)?$value:null;
    }catch(Throwable $e){
        error_log('PASS50 share photo config: '.$e->getMessage());
        $config=null;
    }
    return is_array($config)?$config:null;
}

function p50_share_photo_pdo(): ?PDO {
    static $resolved=false;
    static $pdo=null;
    if($resolved)return $pdo instanceof PDO?$pdo:null;
    $resolved=true;
    $config=p50_share_photo_config();
    $db=is_array($config['db']??null)?$config['db']:[];
    if(($db['name']??'')===''||($db['user']??'')==='')return null;
    try{
        $dsn=sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string)($db['host']??'localhost'),
            (int)($db['port']??3306),
            (string)$db['name'],
            (string)($db['charset']??'utf8mb4')
        );
        $pdo=new PDO($dsn,(string)$db['user'],(string)($db['password']??''),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
    }catch(Throwable $e){
        error_log('PASS50 share photo DB: '.$e->getMessage());
        $pdo=null;
    }
    return $pdo instanceof PDO?$pdo:null;
}

function p50_share_photo_state(): array {
    static $resolved=false;
    static $state=[];
    if($resolved)return $state;
    $resolved=true;
    $pdo=p50_share_photo_pdo();
    if(!$pdo)return [];
    try{
        $raw=$pdo->query("SELECT data FROM app_state WHERE id='public' LIMIT 1")->fetchColumn();
        $decoded=is_string($raw)?json_decode($raw,true):[];
        $state=is_array($decoded)?$decoded:[];
    }catch(Throwable $e){
        error_log('PASS50 share photo state: '.$e->getMessage());
        $state=[];
    }
    return $state;
}

function p50_share_photo_profiles(): array {
    return array_values(array_filter(
        (array)(p50_share_photo_state()['profiles']??[]),
        static fn($profile)=>is_array($profile)
    ));
}

function p50_share_photo_profile_by_id(string $profileId): ?array {
    foreach(p50_share_photo_profiles() as $profile){
        if((string)($profile['id']??'')===$profileId)return $profile;
    }
    return null;
}

function p50_share_photo_normalize_name(string $value): string {
    $value=mb_strtolower(trim(preg_replace('/\s+/u',' ',$value)??''),'UTF-8');
    $plain=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
    if(is_string($plain)&&$plain!=='')$value=strtolower($plain);
    return preg_replace('/[^a-z0-9]+/','',$value)??'';
}

function p50_share_photo_profile_by_name(string $name): ?array {
    $needle=p50_share_photo_normalize_name($name);
    if($needle==='')return null;
    foreach(p50_share_photo_profiles() as $profile){
        if(p50_share_photo_normalize_name((string)($profile['name']??''))===$needle)return $profile;
    }
    return null;
}

function p50_share_photo_reference(array $profile): string {
    if(strtolower((string)($profile['photoStatus']??''))!=='validated')return '';
    $reference=trim((string)($profile['photoUrl']??$profile['photoCandidateUrl']??''));
    if($reference==='')$reference=trim((string)($profile['photoCandidateUrl']??''));
    return $reference;
}

function p50_share_photo_mime(string $bytes): string {
    if($bytes==='')return '';
    $info=@getimagesizefromstring($bytes);
    $mime=is_array($info)?strtolower((string)($info['mime']??'')):'';
    return in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true)?$mime:'';
}

function p50_share_photo_data_asset(string $reference): ?array {
    if(!preg_match('#^data:(image/(?:jpeg|png|webp|gif));base64,([A-Za-z0-9+/=\r\n]+)$#i',$reference,$match))return null;
    $bytes=base64_decode(preg_replace('/\s+/','',$match[2])??'',true);
    if(!is_string($bytes)||$bytes===''||strlen($bytes)>P50_SHARE_PHOTO_MAX_BYTES)return null;
    $mime=p50_share_photo_mime($bytes);
    return $mime!==''?['bytes'=>$bytes,'mime'=>$mime,'source'=>'data']:null;
}

function p50_share_photo_local_path(string $reference): ?string {
    $root=realpath(dirname(__DIR__));
    if(!is_string($root)||$root==='')return null;
    $config=p50_share_photo_config();
    $baseHost='';
    $base=(string)($config['app']['base_url']??'');
    if($base!=='')$baseHost=strtolower((string)(parse_url($base,PHP_URL_HOST)??''));

    $path=$reference;
    if(preg_match('#^https?://#i',$reference)){
        $url=parse_url($reference);
        if(!is_array($url))return null;
        $host=strtolower((string)($url['host']??''));
        if($baseHost===''||$host!==$baseHost)return null;
        $path=(string)($url['path']??'');
    }else{
        $path=(string)(parse_url($reference,PHP_URL_PATH)??$reference);
    }
    $path=rawurldecode($path);
    $path=preg_replace('#^(?:\./|/)+#','',$path)??'';
    if($path===''||str_contains($path,"\0")||preg_match('#(^|/)\.\.(?:/|$)#',$path))return null;
    if(!preg_match('#^(?:uploads|assets)/#',$path))return null;
    $candidate=realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$path));
    if(!is_string($candidate)||!is_file($candidate))return null;
    $prefix=rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if(!str_starts_with($candidate,$prefix))return null;
    $size=filesize($candidate);
    if(!is_int($size)||$size<=0||$size>P50_SHARE_PHOTO_MAX_BYTES)return null;
    return $candidate;
}

function p50_share_photo_local_asset(string $reference): ?array {
    $path=p50_share_photo_local_path($reference);
    if($path===null)return null;
    $bytes=@file_get_contents($path);
    if(!is_string($bytes)||$bytes==='')return null;
    $mime=p50_share_photo_mime($bytes);
    return $mime!==''?['bytes'=>$bytes,'mime'=>$mime,'source'=>'local']:null;
}

function p50_share_photo_public_ipv4(string $host): ?string {
    if(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
        return filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)?$host:null;
    }
    if($host===''||$host==='localhost'||str_ends_with($host,'.local'))return null;
    $records=@dns_get_record($host,DNS_A);
    $ips=[];
    if(is_array($records))foreach($records as $record){
        $ip=(string)($record['ip']??'');
        if($ip!=='')$ips[]=$ip;
    }
    if(!$ips){
        $fallback=@gethostbynamel($host);
        if(is_array($fallback))$ips=$fallback;
    }
    $ips=array_values(array_unique($ips));
    if(!$ips)return null;
    foreach($ips as $ip){
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
    }
    return $ips[0]??null;
}

function p50_share_photo_resolve_redirect(string $base,string $location): string {
    $location=trim($location);
    if($location===''||preg_match('/[\r\n]/',$location))return '';
    if(preg_match('#^https?://#i',$location))return $location;
    $parts=parse_url($base);
    if(!is_array($parts))return '';
    $scheme=(string)($parts['scheme']??'https');
    $host=(string)($parts['host']??'');
    $port=isset($parts['port'])?':'.(int)$parts['port']:'';
    if(str_starts_with($location,'//'))return $scheme.':'.$location;
    if(str_starts_with($location,'/'))return $scheme.'://'.$host.$port.$location;
    $path=(string)($parts['path']??'/');
    $directory=preg_replace('#/[^/]*$#','/',$path)??'/';
    return $scheme.'://'.$host.$port.$directory.$location;
}

function p50_share_photo_cache_dir(): string {
    $dir=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'pass50-share-photo-cache';
    if(!is_dir($dir))@mkdir($dir,0700,true);
    return $dir;
}

function p50_share_photo_cached_asset(string $url): ?array {
    $key=hash('sha256',$url);
    $dir=p50_share_photo_cache_dir();
    $data=$dir.DIRECTORY_SEPARATOR.$key.'.bin';
    $meta=$dir.DIRECTORY_SEPARATOR.$key.'.json';
    if(!is_file($data)||!is_file($meta))return null;
    $age=time()-(int)filemtime($data);
    if($age<0||$age>P50_SHARE_PHOTO_CACHE_SECONDS)return null;
    $metadata=json_decode((string)@file_get_contents($meta),true);
    $mime=is_array($metadata)?(string)($metadata['mime']??''):'';
    $bytes=@file_get_contents($data);
    if(!is_string($bytes)||$bytes===''||strlen($bytes)>P50_SHARE_PHOTO_MAX_BYTES||p50_share_photo_mime($bytes)!==$mime)return null;
    return ['bytes'=>$bytes,'mime'=>$mime,'source'=>'cache'];
}

function p50_share_photo_store_cache(string $url,array $asset): void {
    $bytes=(string)($asset['bytes']??'');
    $mime=(string)($asset['mime']??'');
    if($bytes===''||$mime==='')return;
    $key=hash('sha256',$url);
    $dir=p50_share_photo_cache_dir();
    @file_put_contents($dir.DIRECTORY_SEPARATOR.$key.'.bin',$bytes,LOCK_EX);
    @file_put_contents($dir.DIRECTORY_SEPARATOR.$key.'.json',json_encode(['mime'=>$mime,'savedAt'=>gmdate('c')]),LOCK_EX);
}

function p50_share_photo_remote_asset(string $url,int $redirects=0): ?array {
    $cached=p50_share_photo_cached_asset($url);
    if($cached)return $cached;
    if(!function_exists('curl_init')||$redirects>2)return null;
    $parts=parse_url($url);
    if(!is_array($parts))return null;
    $scheme=strtolower((string)($parts['scheme']??''));
    $host=strtolower((string)($parts['host']??''));
    if(!in_array($scheme,['https','http'],true)||$host===''||isset($parts['user'])||isset($parts['pass']))return null;
    $port=(int)($parts['port']??($scheme==='https'?443:80));
    if(($scheme==='https'&&$port!==443)||($scheme==='http'&&$port!==80))return null;
    $ip=p50_share_photo_public_ipv4($host);
    if($ip===null)return null;

    $bytes='';
    $location='';
    $ch=curl_init($url);
    if($ch===false)return null;
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>9,
        CURLOPT_USERAGENT=>'PASS50-SharePhoto/1.0',
        CURLOPT_HTTPHEADER=>['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif;q=0.9,*/*;q=0.1'],
        CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip],
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$bytes): int {
            if(strlen($bytes)+strlen($chunk)>P50_SHARE_PHOTO_MAX_BYTES)return 0;
            $bytes.=$chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$location): int {
            if(stripos($line,'Location:')===0)$location=trim(substr($line,9));
            return strlen($line);
        },
    ]);
    $ok=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($status>=300&&$status<400&&$location!==''){
        $next=p50_share_photo_resolve_redirect($url,$location);
        return $next!==''?p50_share_photo_remote_asset($next,$redirects+1):null;
    }
    if($ok===false||$status<200||$status>=300||$bytes==='')return null;
    $mime=p50_share_photo_mime($bytes);
    if($mime==='')return null;
    $asset=['bytes'=>$bytes,'mime'=>$mime,'source'=>'remote'];
    p50_share_photo_store_cache($url,$asset);
    return $asset;
}

function p50_share_photo_asset_from_reference(string $reference): ?array {
    $reference=trim($reference);
    if($reference==='')return null;
    $data=p50_share_photo_data_asset($reference);
    if($data)return $data;
    $local=p50_share_photo_local_asset($reference);
    if($local)return $local;
    if(!preg_match('#^https?://#i',$reference))return null;
    return p50_share_photo_remote_asset($reference);
}

function p50_share_photo_asset_for_profile(array $profile): ?array {
    $reference=p50_share_photo_reference($profile);
    return $reference!==''?p50_share_photo_asset_from_reference($reference):null;
}

function p50_share_photo_asset(string $profileId): ?array {
    $profile=p50_share_photo_profile_by_id($profileId);
    return $profile?p50_share_photo_asset_for_profile($profile):null;
}
