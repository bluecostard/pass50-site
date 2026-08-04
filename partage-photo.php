<?php
declare(strict_types=1);

require_once __DIR__.'/api/share-photo-core.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

$profileId=trim((string)($_GET['id']??''));
if(!preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$profileId)){
    http_response_code(404);
    exit;
}

$asset=p50_share_photo_asset($profileId);
if(!$asset){
    http_response_code(404);
    header('Cache-Control: public, max-age=300');
    exit;
}

$bytes=(string)($asset['bytes']??'');
$mime=(string)($asset['mime']??'');
if($bytes===''||$mime===''){
    http_response_code(404);
    exit;
}

$etag='"'.hash('sha256',$profileId.'|'.$bytes).'"';
if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){
    http_response_code(304);
    header('ETag: '.$etag);
    header('Cache-Control: public, max-age=21600, stale-while-revalidate=86400');
    exit;
}

header('Content-Type: '.$mime);
header('Content-Length: '.strlen($bytes));
header('Cache-Control: public, max-age=21600, stale-while-revalidate=86400');
header('ETag: '.$etag);
echo $bytes;
