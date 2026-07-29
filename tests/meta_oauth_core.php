<?php
declare(strict_types=1);
$config=['app'=>['base_url'=>'https://www.pass50.store'],'meta_oauth'=>['app_id'=>'123456789','app_secret'=>str_repeat('s',40),'redirect_uri'=>'https://www.pass50.store/api/meta-oauth-callback.php','graph_version'=>'v25.0','token_encryption_key'=>base64_encode(str_repeat('k',32))]];
require dirname(__DIR__).'/api/meta-oauth-core.php';
function must(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
$secret='meta-token-example';$encrypted=p50mo_encrypt($secret);must($encrypted!==$secret,'Le jeton doit être chiffré.');must(p50mo_decrypt($encrypted)===$secret,'Le jeton doit être déchiffrable.');
$nonce=p50mo_b64e(random_bytes(24));$sid=hash('sha256','session-token');$state=p50mo_create_state($sid,$nonce);must(p50mo_verify_state($state,$nonce)===$sid,'L’état OAuth doit conserver la session.');
$rejected=false;try{p50mo_verify_state($state,$nonce.'x');}catch(Throwable){$rejected=true;}must($rejected,'Un nonce différent doit être rejeté.');
must(in_array('pages_show_list',P50MO_REQUIRED_SCOPES,true),'pages_show_list requis.');must(in_array('instagram_basic',P50MO_REQUIRED_SCOPES,true),'instagram_basic requis.');must(p50mo_normalize_url('https://www.instagram.com/Test/')==='instagram.com/test','Normalisation Instagram stable.');
$payload=p50mo_b64e((string)json_encode(['algorithm'=>'HMAC-SHA256','user_id'=>'meta-user-123'],JSON_UNESCAPED_SLASHES));
$signature=p50mo_b64e(hash_hmac('sha256',$payload,$config['meta_oauth']['app_secret'],true));
$parsed=p50mo_parse_signed_request($signature.'.'.$payload);must(($parsed['user_id']??'')==='meta-user-123','La demande Meta signée doit être validée.');
$bad=false;try{p50mo_parse_signed_request(p50mo_b64e(str_repeat('x',32)).'.'.$payload);}catch(Throwable){$bad=true;}must($bad,'Une signature de suppression falsifiée doit être rejetée.');
echo json_encode(['ok'=>true,'cases'=>9],JSON_UNESCAPED_SLASHES).PHP_EOL;
