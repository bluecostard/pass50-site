<?php
declare(strict_types=1);

require_once __DIR__.'/api/share-photo-core.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

function p50_share_photo_resize(string $bytes,int $size): ?array {
    if($size<32||$size>512||!extension_loaded('gd')||!function_exists('imagecreatefromstring'))return null;
    $source=@imagecreatefromstring($bytes);
    if(!$source instanceof GdImage)return null;
    $width=imagesx($source);$height=imagesy($source);
    if($width<=0||$height<=0||$width>12000||$height>12000){imagedestroy($source);return null;}
    $side=min($width,$height);
    $sourceX=(int)floor(($width-$side)/2);
    $sourceY=(int)floor(($height-$side)/2);
    $target=imagecreatetruecolor($size,$size);
    if(!$target instanceof GdImage){imagedestroy($source);return null;}
    $background=imagecolorallocate($target,10,13,10);
    imagefill($target,0,0,$background);
    imagecopyresampled($target,$source,0,0,$sourceX,$sourceY,$size,$size,$side,$side);
    ob_start();
    imagejpeg($target,null,86);
    $resized=ob_get_clean();
    imagedestroy($target);imagedestroy($source);
    return is_string($resized)&&$resized!==''?['bytes'=>$resized,'mime'=>'image/jpeg']:null;
}

$profileId=trim((string)($_GET['id']??''));
if(!preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$profileId)){
    http_response_code(404);
    exit;
}
$size=max(0,min(512,(int)($_GET['size']??0)));

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
if($size>=32){
    $resized=p50_share_photo_resize($bytes,$size);
    if($resized){$bytes=$resized['bytes'];$mime=$resized['mime'];}
}

$etag='"'.hash('sha256',$profileId.'|'.$size.'|'.$bytes).'"';
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
