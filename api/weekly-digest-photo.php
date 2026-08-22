<?php
declare(strict_types=1);

require_once __DIR__ . '/share-photo-core.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: cross-origin');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300, stale-while-revalidate=1800');

function p50_weekly_digest_photo_resize(string $bytes, int $size): ?array {
    if ($size < 32 || $size > 512 || !extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $source = @imagecreatefromstring($bytes);
    if (!$source instanceof GdImage) {
        return null;
    }
    $width = imagesx($source);
    $height = imagesy($source);
    if ($width <= 0 || $height <= 0 || $width > 12000 || $height > 12000) {
        imagedestroy($source);
        return null;
    }
    $side = min($width, $height);
    $sourceX = (int) floor(($width - $side) / 2);
    $sourceY = (int) floor(($height - $side) / 2);
    $target = imagecreatetruecolor($size, $size);
    if (!$target instanceof GdImage) {
        imagedestroy($source);
        return null;
    }
    $background = imagecolorallocate($target, 10, 13, 10);
    imagefill($target, 0, 0, $background);
    imagecopyresampled($target, $source, 0, 0, $sourceX, $sourceY, $size, $size, $side, $side);
    ob_start();
    imagejpeg($target, null, 86);
    $resized = ob_get_clean();
    imagedestroy($target);
    imagedestroy($source);
    return is_string($resized) && $resized !== '' ? ['bytes' => $resized, 'mime' => 'image/jpeg'] : null;
}

function p50_weekly_digest_photo_prod_fetch(string $profileId, int $size): ?array {
    $url = 'https://pass50.store/partage-photo.php?id=' . rawurlencode($profileId) . '&size=' . $size;
    if (!function_exists('curl_init')) {
        $bytes = @file_get_contents($url);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        $mime = p50_share_photo_mime($bytes);
        return $mime !== '' ? ['bytes' => $bytes, 'mime' => $mime] : null;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'PASS50-WeeklyDigestPhoto/1.0',
    ]);
    $bytes = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($bytes === false || $status < 200 || $status >= 300) {
        return null;
    }
    $mime = p50_share_photo_mime((string) $bytes);
    return $mime !== '' ? ['bytes' => (string) $bytes, 'mime' => $mime] : null;
}

$profileId = trim((string) ($_GET['id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $profileId)) {
    http_response_code(404);
    exit;
}
$size = max(0, min(512, (int) ($_GET['size'] ?? 0)));

$asset = p50_share_photo_asset($profileId);
if (!$asset) {
    $asset = p50_weekly_digest_photo_prod_fetch($profileId, $size > 0 ? $size : 480);
}
if (!$asset) {
    http_response_code(404);
    exit;
}

$bytes = (string) ($asset['bytes'] ?? '');
$mime = (string) ($asset['mime'] ?? '');
if ($bytes === '' || $mime === '') {
    http_response_code(404);
    exit;
}
if ($size >= 32) {
    $resized = p50_weekly_digest_photo_resize($bytes, $size);
    if ($resized) {
        $bytes = $resized['bytes'];
        $mime = $resized['mime'];
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) strlen($bytes));
echo $bytes;
