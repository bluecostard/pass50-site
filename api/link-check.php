<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/http-tools.php';
require_once __DIR__ . '/data-engine-core.php';
require_method('POST');
$user = auth_user();
require_role($user, 'owner', 'admin');
$in = json_input();
$items = (array)($in['links'] ?? []);
if (count($items) > 40) $items = array_slice($items,0,40,true);
$out = [];
foreach ($items as $platform => $urlValue) {
    $url = trim((string)$urlValue);
    if ($url === '' || !filter_var($url,FILTER_VALIDATE_URL) || !p50_public_http_url($url)) {
        $out[$platform] = ['status'=>'invalid','httpStatus'=>0,'url'=>$url,'message'=>'Lien invalide'];
        continue;
    }
    $r = p50_http_fetch($url,12,'text/html,*/*;q=0.6',true);
    if (!$r['ok'] && in_array($r['status'],[403,405,429],true)) $r = p50_http_fetch($url,12,'text/html,*/*;q=0.6',false);
    $checkedUrl = $r['finalUrl'] ?: $url;
    $hostOk = p50_platform_host_ok((string)$platform,$checkedUrl);
    $isSearch = (bool)preg_match('#/(search|results|explore/search)#i', (string)(parse_url($checkedUrl,PHP_URL_PATH) ?: '')) || str_contains((string)(parse_url($checkedUrl,PHP_URL_QUERY) ?: ''),'search_query=');
    $isDirect = $hostOk && p50_de_direct_social_path((string)$platform,$checkedUrl);
    $status = !$hostOk ? 'wrong_platform' : ($isSearch ? 'search_not_official' : (!$isDirect ? 'generic_or_content' : ($r['ok'] ? 'ok' : (in_array($r['status'],[403,429],true) ? 'blocked_but_exists' : 'broken'))));
    $out[$platform] = [
        'status'=>$status,
        'httpStatus'=>$r['status'],
        'url'=>$url,
        'finalUrl'=>$r['finalUrl'],
        'contentType'=>$r['contentType'],
        'message'=>match($status){
            'ok'=>'Lien direct accessible',
            'blocked_but_exists'=>'La plateforme bloque le contrôle automatique, mais le domaine répond',
            'search_not_official'=>'Lien de recherche : remplacez-le par le profil officiel exact',
            'generic_or_content'=>'Lien générique ou contenu isolé : utilisez l’URL directe du profil officiel',
            'wrong_platform'=>'Le domaine ne correspond pas à la plateforme',
            default=>'Lien inaccessible ou supprimé',
        },
    ];
}
json_response(['ok'=>true,'results'=>$out,'checkedAt'=>gmdate('c')]);
