<?php
declare(strict_types=1);

const P50MO_REQUIRED_SCOPES = ['pages_show_list','pages_read_engagement','pages_manage_metadata','instagram_basic'];
const P50MO_STATE_TTL_SECONDS = 600;
const P50MO_NONCE_COOKIE = 'p50_meta_oauth_nonce';
const P50MO_NONCE_PATH = '/api/meta-oauth-callback.php';

function p50mo_config_value(array $oauth,string $key,string $env): string {
    $value=trim((string)($oauth[$key]??''));
    return $value!==''?$value:trim((string)(getenv($env)?:''));
}
function p50mo_config(): array {
    global $config;
    $oauth=is_array($config['meta_oauth']??null)?$config['meta_oauth']:[];
    $google=is_array($config['google_oauth']??null)?$config['google_oauth']:[];
    $values=[
        'app_id'=>p50mo_config_value($oauth,'app_id','META_APP_ID'),
        'app_secret'=>p50mo_config_value($oauth,'app_secret','META_APP_SECRET'),
        'redirect_uri'=>p50mo_config_value($oauth,'redirect_uri','META_REDIRECT_URI'),
        'graph_version'=>p50mo_config_value($oauth,'graph_version','META_GRAPH_VERSION'),
        'token_encryption_key'=>p50mo_config_value($oauth,'token_encryption_key','PASS50_TOKEN_ENCRYPTION_KEY'),
    ];
    if($values['token_encryption_key']==='')$values['token_encryption_key']=trim((string)($google['token_encryption_key']??''));
    if($values['app_id']===''||$values['app_secret']===''||$values['redirect_uri']===''||$values['graph_version']==='')throw new RuntimeException('Configuration OAuth Meta incomplète dans api/config.php.');
    if(!preg_match('/^v\d+\.\d+$/',$values['graph_version']))throw new RuntimeException('Version Graph API Meta invalide.');
    if(!filter_var($values['redirect_uri'],FILTER_VALIDATE_URL)||!str_starts_with($values['redirect_uri'],'https://'))throw new RuntimeException('URI de redirection OAuth Meta invalide.');
    if($values['token_encryption_key']==='')throw new RuntimeException('Clé de chiffrement OAuth manquante.');
    if(!function_exists('openssl_encrypt')||!function_exists('openssl_decrypt'))throw new RuntimeException('Extension OpenSSL indisponible.');
    return $values;
}
function p50mo_ensure_schema(): void {
    static $done=false;if($done)return;$done=true;
    $sql=file_get_contents(dirname(__DIR__).'/migration-meta-oauth-v1.sql');
    if($sql===false)throw new RuntimeException('Migration OAuth Meta introuvable.');
    foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[] as $statement){$statement=trim($statement);if($statement!=='')db()->exec($statement);}
}
function p50mo_b64e(string $value): string {return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function p50mo_b64d(string $value): string {$padding=(4-strlen($value)%4)%4;$decoded=base64_decode(strtr($value.str_repeat('=',$padding),'-_','+/'),true);if($decoded===false)throw new RuntimeException('Valeur OAuth invalide.');return $decoded;}
function p50mo_key(): string {$raw=p50mo_config()['token_encryption_key'];$decoded=base64_decode($raw,true);if($decoded!==false&&strlen($decoded)===32)return $decoded;if(strlen($raw)>=32)return hash('sha256',$raw,true);throw new RuntimeException('Clé OAuth trop courte.');}
function p50mo_encrypt(string $plain): string {
    if($plain==='')return '';$iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',p50mo_key(),OPENSSL_RAW_DATA,$iv,$tag,'PASS50:meta-oauth:v1',16);
    if($cipher===false||strlen($tag)!==16)throw new RuntimeException('Chiffrement OAuth Meta impossible.');
    return 'v1.'.p50mo_b64e($iv).'.'.p50mo_b64e($tag).'.'.p50mo_b64e($cipher);
}
function p50mo_decrypt(?string $payload): string {
    if(!$payload)return '';$parts=explode('.',$payload);if(count($parts)!==4||$parts[0]!=='v1')throw new RuntimeException('Format OAuth Meta inconnu.');
    $plain=openssl_decrypt(p50mo_b64d($parts[3]),'aes-256-gcm',p50mo_key(),OPENSSL_RAW_DATA,p50mo_b64d($parts[1]),p50mo_b64d($parts[2]),'PASS50:meta-oauth:v1');
    if($plain===false)throw new RuntimeException('Déchiffrement OAuth Meta impossible.');return $plain;
}
function p50mo_http(string $url,string $method='GET',array $query=[],?array $form=null,array $headers=[]): array {
    if($query)$url.=(str_contains($url,'?')?'&':'?').http_build_query($query,'','&',PHP_QUERY_RFC3986);
    $ch=curl_init($url);if($ch===false)throw new RuntimeException('cURL indisponible.');
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_USERAGENT=>'PASS50-Meta-OAuth/1.0',CURLOPT_HTTPHEADER=>$headers];
    if($method==='POST'){$opts[CURLOPT_POST]=true;$opts[CURLOPT_POSTFIELDS]=http_build_query($form??[],'','&',PHP_QUERY_RFC3986);}
    elseif($method==='DELETE')$opts[CURLOPT_CUSTOMREQUEST]='DELETE';
    curl_setopt_array($ch,$opts);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false)throw new RuntimeException('Erreur réseau OAuth Meta : '.$error);$json=json_decode((string)$body,true);
    return ['status'=>$status,'body'=>(string)$body,'json'=>is_array($json)?$json:[]];
}
function p50mo_error(array $response,string $fallback): RuntimeException {
    $error=$response['json']['error']??null;$message=is_array($error)?trim((string)($error['message']??'')):'';
    return new RuntimeException($message!==''?$fallback.' : '.$message:$fallback.' (HTTP '.(int)($response['status']??0).').');
}
function p50mo_proof(string $token): string {return hash_hmac('sha256',$token,p50mo_config()['app_secret']);}
function p50mo_graph(string $path,string $token,array $fields=[]): array {
    $cfg=p50mo_config();$query=['access_token'=>$token,'appsecret_proof'=>p50mo_proof($token)]+$fields;
    return p50mo_http('https://graph.facebook.com/'.$cfg['graph_version'].'/'.ltrim($path,'/'),'GET',$query);
}
function p50mo_connection(string $userId): ?array {$stmt=db()->prepare('SELECT * FROM p50_meta_oauth_connections WHERE user_id=? LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch();return is_array($row)?$row:null;}
function p50mo_assets(string $userId): array {$stmt=db()->prepare('SELECT * FROM p50_meta_oauth_assets WHERE user_id=? AND status=\'active\' ORDER BY platform,asset_name');$stmt->execute([$userId]);return $stmt->fetchAll();}
function p50mo_normalize_url(string $url): string {$url=trim($url);if($url==='')return '';if(!preg_match('#^https?://#i',$url))$url='https://'.$url;$parts=parse_url($url);if(!$parts||empty($parts['host']))return '';return strtolower(preg_replace('/^www\./i','',(string)$parts['host']).'/'.trim((string)($parts['path']??''),'/'));}
function p50mo_match_profile(string $platform,string $profileUrl,string $username=''): ?string {
    $candidates=[];if($profileUrl!=='')$candidates[]=p50mo_normalize_url($profileUrl);
    if($platform==='Instagram'&&$username!=='')$candidates[]=p50mo_normalize_url('https://www.instagram.com/'.ltrim($username,'@').'/');
    $candidates=array_values(array_unique(array_filter($candidates)));if(!$candidates)return null;
    $stmt=db()->prepare("SELECT profile_id,normalized_url FROM p50_social_links WHERE platform=? AND status='verified'");$stmt->execute([$platform]);
    foreach($stmt->fetchAll() as $row)if(in_array(p50mo_normalize_url((string)$row['normalized_url']),$candidates,true))return (string)$row['profile_id'];
    return null;
}
function p50mo_cookie_options(int $expires): array {return ['expires'=>$expires,'path'=>P50MO_NONCE_PATH,'secure'=>true,'httponly'=>true,'samesite'=>'Lax'];}
function p50mo_set_nonce(string $nonce): void {if(!setcookie(P50MO_NONCE_COOKIE,$nonce,p50mo_cookie_options(time()+P50MO_STATE_TTL_SECONDS)))throw new RuntimeException('Création du cookie OAuth Meta impossible.');}
function p50mo_clear_nonce(): void {setcookie(P50MO_NONCE_COOKIE,'',p50mo_cookie_options(time()-3600));}
function p50mo_state_key(): string {return hash_hmac('sha256','PASS50:meta-oauth-state:v1',p50mo_key(),true);}
function p50mo_create_state(string $sessionHash,string $nonce): string {
    $payload=['v'=>1,'sid'=>strtolower($sessionHash),'nh'=>hash('sha256',$nonce),'iat'=>time(),'exp'=>time()+P50MO_STATE_TTL_SECONDS,'jti'=>p50mo_b64e(random_bytes(18))];
    $encoded=p50mo_b64e((string)json_encode($payload,JSON_UNESCAPED_SLASHES));return $encoded.'.'.p50mo_b64e(hash_hmac('sha256',$encoded,p50mo_state_key(),true));
}
function p50mo_verify_state(string $state,string $nonce): string {
    if(substr_count($state,'.')!==1||$nonce==='')throw new RuntimeException('État OAuth Meta invalide.');[$encoded,$signature]=explode('.',$state,2);
    $expected=p50mo_b64e(hash_hmac('sha256',$encoded,p50mo_state_key(),true));if(!hash_equals($expected,$signature))throw new RuntimeException('Signature OAuth Meta invalide.');
    $payload=json_decode(p50mo_b64d($encoded),true);$now=time();if(!is_array($payload)||(int)($payload['v']??0)!==1||!hash_equals((string)($payload['nh']??''),hash('sha256',$nonce))||(int)($payload['exp']??0)<$now||(int)($payload['iat']??0)>$now+60)throw new RuntimeException('État OAuth Meta expiré.');
    $sid=strtolower(trim((string)($payload['sid']??'')));if(!preg_match('/^[a-f0-9]{64}$/',$sid))throw new RuntimeException('Session OAuth Meta invalide.');return $sid;
}
function p50mo_redirect(string $status,string $code=''): never {
    global $config;$base=rtrim((string)($config['app']['base_url']??''),'/');$query=['meta_oauth'=>$status];if($code!=='')$query['code']=$code;$target=$base.'/?'.http_build_query($query);
    $origin=(string)(parse_url($base,PHP_URL_SCHEME).'://'.parse_url($base,PHP_URL_HOST));$message=['source'=>'PASS50_META_OAUTH','status'=>$status,'code'=>$code];
    header_remove('Content-Type');header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>PASS50 · Meta</title><body><p>Connexion Meta terminée.</p><script>(function(){var m='.json_encode($message).';var t='.json_encode($target).';var o='.json_encode($origin).';try{if(window.opener&&!window.opener.closed){window.opener.postMessage(m,o);window.close();return;}}catch(e){}window.location.replace(t);}());</script></body></html>';exit;
}
