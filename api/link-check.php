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
    $normalized = p50_de_normalize_social_url((string)$platform,$url);
    $submittedHostOk = $normalized !== '' && p50_platform_host_ok((string)$platform,$normalized);
    $submittedDirect = $submittedHostOk && p50_de_direct_social_path((string)$platform,$normalized);
    $submittedPath = (string)(parse_url($normalized ?: $url,PHP_URL_PATH) ?: '');
    $submittedQuery = (string)(parse_url($normalized ?: $url,PHP_URL_QUERY) ?: '');
    $submittedSearch = (bool)preg_match('#/(search|results|explore/search)#i',$submittedPath) || str_contains($submittedQuery,'search_query=');
    if (!$submittedHostOk || $submittedSearch || !$submittedDirect) {
        $status = !$submittedHostOk ? 'wrong_platform' : ($submittedSearch ? 'search_not_official' : 'generic_or_content');
        $out[$platform] = [
            'status'=>$status,'httpStatus'=>0,'url'=>$url,'finalUrl'=>'','contentType'=>'',
            'message'=>match($status){
                'search_not_official'=>'Lien de recherche : remplacez-le par le profil officiel exact',
                'generic_or_content'=>'Lien générique ou contenu isolé : utilisez l’URL directe du profil officiel',
                default=>'Le domaine ne correspond pas à la plateforme',
            },
        ];
        continue;
    }
    $r = p50_http_fetch($normalized,12,'text/html,*/*;q=0.6',true);
    if (!$r['ok'] && in_array($r['status'],[403,405,429],true)) $r = p50_http_fetch($normalized,12,'text/html,*/*;q=0.6',false);
    $checkedUrl = $r['finalUrl'] ?: $normalized;
    $finalNormalized = p50_de_normalize_social_url((string)$platform,$checkedUrl);
    $redirectedAway = $finalNormalized !== '' && (!p50_platform_host_ok((string)$platform,$finalNormalized) || !p50_de_direct_social_path((string)$platform,$finalNormalized));
    $explicitMissing = in_array((int)$r['status'],[404,410],true) && !$redirectedAway;
    $remoteBlocked = $redirectedAway || in_array((int)$r['status'],[0,401,403,405,429,451],true) || (int)$r['status']>=500;
    // Un profil direct ne doit pas être déclaré cassé simplement parce que le réseau
    // refuse les robots ou redirige vers login/challenge/consent.
    $status = $explicitMissing ? 'broken' : ($remoteBlocked ? 'blocked_but_exists' : ($r['ok'] ? 'ok' : 'blocked_but_exists'));
    $out[$platform] = [
        'status'=>$status,
        'httpStatus'=>$r['status'],
        'url'=>$normalized,
        'finalUrl'=>$redirectedAway?'':$r['finalUrl'],
        'contentType'=>$r['contentType'],
        'message'=>match($status){
            'ok'=>'Lien direct accessible',
            'blocked_but_exists'=>'Profil direct reconnu ; la plateforme empêche le contrôle automatique',
            default=>'Profil introuvable ou supprimé',
        },
    ];
}
json_response(['ok'=>true,'results'=>$out,'checkedAt'=>gmdate('c')]);
