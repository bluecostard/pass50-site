<?php
declare(strict_types=1);

function p50yo_base64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
function p50yo_base64url_decode(string $value): string {
    $padding=(4-strlen($value)%4)%4;
    $decoded=base64_decode(strtr($value.str_repeat('=',$padding),'-_','+/'),true);
    if($decoded===false)throw new RuntimeException('fixture decode');
    return $decoded;
}
function p50yo_encryption_key(): string {
    return str_repeat("\x42",32);
}

require dirname(__DIR__).'/api/youtube-oauth-state-v2.php';

function yo_must(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
function yo_signed_state(array $payload): string {
    $encoded=p50yo_base64url_encode((string)json_encode($payload,JSON_UNESCAPED_SLASHES));
    $signature=p50yo_base64url_encode(hash_hmac('sha256',$encoded,p50yo_state_key(),true));
    return $encoded.'.'.$signature;
}

$userId='12345678-1234-4abc-8def-1234567890ab';
$state=p50yo_create_state($userId);
yo_must(p50yo_verify_state($state)===$userId,'L’état signé restitue le bon utilisateur.');

$tampered=substr($state,0,-1).(str_ends_with($state,'A')?'B':'A');
$tamperRejected=false;
try{p50yo_verify_state($tampered);}catch(Throwable){$tamperRejected=true;}
yo_must($tamperRejected,'Un état altéré est refusé.');

$now=time();
$expired=yo_signed_state(['v'=>2,'uid'=>$userId,'iat'=>$now-800,'exp'=>$now-1,'nonce'=>'fixture']);
$expiredRejected=false;
try{p50yo_verify_state($expired);}catch(Throwable){$expiredRejected=true;}
yo_must($expiredRejected,'Un état expiré est refusé.');

$future=yo_signed_state(['v'=>2,'uid'=>$userId,'iat'=>$now+120,'exp'=>$now+600,'nonce'=>'fixture']);
$futureRejected=false;
try{p50yo_verify_state($future);}catch(Throwable){$futureRejected=true;}
yo_must($futureRejected,'Un état émis trop loin dans le futur est refusé.');

echo json_encode(['ok'=>true,'tests'=>4],JSON_UNESCAPED_SLASHES).PHP_EOL;
