<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$user = auth_user();
require_role($user, 'owner', 'admin');
$in = json_input();

// RÈGLE PASS50 NON NÉGOCIABLE :
// Si l’administrateur n’a pas confirmé que l’image représente réellement l’influenceur,
// le script ne télécharge aucun fichier.
if (($in['confirmedRepresentation'] ?? false) !== true) {
    json_response(['error' => 'Téléchargement bloqué : confirmez d’abord que cette photo représente bien l’influenceur.'], 422);
}

$profileId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($in['profileId'] ?? '')) ?: '';
$profileName = trim((string)($in['profileName'] ?? ''));
$url = trim((string)($in['url'] ?? ''));
$sourcePage = trim((string)($in['sourcePage'] ?? ''));
if ($profileId === '' || $profileName === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    json_response(['error' => 'Données image invalides.'], 422);
}

function public_http_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) return false;
    $host = (string)($parts['host'] ?? '');
    if ($host === '' || in_array(strtolower($host), ['localhost','127.0.0.1','::1'], true)) return false;
    $ips = gethostbynamel($host) ?: [];
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

if (!public_http_url($url)) json_response(['error' => 'Source distante refusée.'], 422);

$tmp = tempnam(sys_get_temp_dir(), 'p50img_');
if ($tmp === false) json_response(['error' => 'Fichier temporaire impossible.'], 500);
$fh = fopen($tmp, 'wb');
if ($fh === false) json_response(['error' => 'Fichier temporaire impossible.'], 500);

$maxBytes = min((int)($config['upload']['max_bytes'] ?? 5_000_000), 6_000_000);
$downloaded = 0;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_FILE => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 7,
    CURLOPT_USERAGENT => 'PASS50-MediaCollector/1.0 (+https://pass50.store)',
    CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,*/*;q=0.5'],
    CURLOPT_NOPROGRESS => false,
    CURLOPT_PROGRESSFUNCTION => static function($resource, float $downloadSize, float $downloadedNow) use ($maxBytes): int {
        return $downloadedNow > $maxBytes ? 1 : 0;
    },
]);
$ok = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
$error = curl_error($ch);
curl_close($ch);
fclose($fh);

if ($ok !== true || $status < 200 || $status >= 300) {
    @unlink($tmp);
    json_response(['error' => 'Téléchargement distant impossible.' . ($error ? ' ' . $error : '')], 422);
}
$size = filesize($tmp) ?: 0;
if ($size < 8_000 || $size > $maxBytes) {
    @unlink($tmp);
    json_response(['error' => 'Image trop petite ou trop volumineuse.'], 422);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmp);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    @unlink($tmp);
    json_response(['error' => 'Format image non autorisé.'], 422);
}
$dimensions = @getimagesize($tmp);
if (!$dimensions || $dimensions[0] < 200 || $dimensions[1] < 200) {
    @unlink($tmp);
    json_response(['error' => 'Résolution insuffisante.'], 422);
}

$dir = dirname(__DIR__) . '/uploads/profile';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    @unlink($tmp);
    json_response(['error' => 'Dossier média inaccessible.'], 500);
}
$name = $profileId . '-validated-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
$dest = $dir . '/' . $name;
if (!rename($tmp, $dest)) {
    @unlink($tmp);
    json_response(['error' => 'Enregistrement impossible.'], 500);
}
$urlOut = rtrim($config['app']['base_url'], '/') . '/uploads/profile/' . $name;
json_response([
    'ok' => true,
    'url' => $urlOut,
    'sourcePage' => $sourcePage,
    'profileName' => $profileName,
    'width' => $dimensions[0],
    'height' => $dimensions[1],
], 201);
