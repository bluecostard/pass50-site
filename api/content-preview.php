<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/http-tools.php';
$user = auth_user();
require_role($user, 'owner', 'admin');
require_method('POST');
$in = json_input();
$url = trim((string)($in['url'] ?? ''));
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !p50_public_http_url($url)) {
    json_response(['error'=>'URL originale invalide.'], 422);
}

function p50_exact_content_path(string $platform, string $url): bool {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?: '');
    if ($platform === 'YouTube') return str_contains($host,'youtu.be') || preg_match('#/(watch|shorts|live)/#i',$path) || str_contains($query,'v=');
    if ($platform === 'TikTok') return preg_match('#/@[^/]+/video/\d+#i',$path) === 1;
    if ($platform === 'Instagram') return preg_match('#/(p|reel|tv)/[^/]+#i',$path) === 1;
    if ($platform === 'Facebook') return preg_match('#/(videos|reel|posts|watch|share/(?:v|r|p))/#i',$path) === 1 || preg_match('/(?:^|&)(?:v|story_fbid|fbid)=/i',$query) === 1 || str_contains($host,'fb.watch');
    if ($platform === 'X') return preg_match('#/status/\d+#i',$path) === 1;
    return trim($path,'/') !== '';
}

$platform = p50_platform($url);
if (!p50_exact_content_path($platform,$url)) {
    json_response(['error'=>'Ce lien semble pointer vers un profil ou une page générale. Colle le lien exact de la vidéo, publication ou article.','platform'=>$platform],422);
}

$title='';$author='';$thumbnail='';$canonical=$url;$source='Métadonnées publiques';$blocked=false;
try {
    if ($platform === 'YouTube') {
        $o = p50_json_get('https://www.youtube.com/oembed?format=json&url='.rawurlencode($url),15);
        if ($o) { $title=(string)($o['title']??'');$author=(string)($o['author_name']??'');$thumbnail=(string)($o['thumbnail_url']??'');$source='YouTube oEmbed'; }
    } elseif ($platform === 'TikTok') {
        $o = p50_json_get('https://www.tiktok.com/oembed?url='.rawurlencode($url),15);
        if ($o) { $title=(string)($o['title']??'');$author=(string)($o['author_name']??'');$thumbnail=(string)($o['thumbnail_url']??'');$canonical=(string)($o['author_url']??$url);$canonical=$url;$source='TikTok oEmbed'; }
    }
    if ($title === '' || $thumbnail === '') {
        $r = p50_http_fetch($url,15,'text/html,*/*;q=0.7');
        $blocked = in_array((int)$r['status'],[403,429],true);
        if ($r['body'] !== '') {
            $m = p50_page_metadata($r['body'],$r['finalUrl'] ?: $url);
            if ($title==='') $title=(string)($m['title']??'');
            if ($thumbnail==='') $thumbnail=(string)($m['image']??'');
            if (!empty($m['canonical'])) {
                $candidate=(string)$m['canonical'];
                if(p50_platform($candidate)===$platform&&p50_exact_content_path($platform,$candidate))$canonical=$candidate;
            }
            $source = $source === 'Métadonnées publiques' ? 'Open Graph' : $source.' + Open Graph';
        }
        if ($r['finalUrl']&&p50_platform($r['finalUrl'])===$platform&&p50_exact_content_path($platform,$r['finalUrl'])) $canonical=$r['finalUrl'];
    }
} catch (Throwable $e) {}

json_response([
    'ok'=>true,
    'validContent'=>true,
    'url'=>$url,
    'canonicalUrl'=>$canonical ?: $url,
    'platform'=>$platform,
    'title'=>$title,
    'author'=>$author,
    'thumbnail'=>$thumbnail,
    'source'=>$source,
    'blocked'=>$blocked,
    'message'=>$blocked?'La plateforme bloque la lecture automatique, mais le format du lien original est valide.':'Lien original reconnu.',
]);
