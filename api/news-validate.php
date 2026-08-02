<?php
declare(strict_types=1);

/**
 * Validation Actualité → FI + pont métriques.
 * Exige une confirmation explicite, enregistre le contenu mesurable et enqueue une capture.
 */
require __DIR__.'/bootstrap.php';
require __DIR__.'/http-tools.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/metrics-schema-core.php';
require __DIR__.'/metrics-orchestrator-core.php';

$user=auth_user();
require_role($user,'owner','admin');
require_method('POST');

$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$url=trim((string)($in['url']??''));
$confirmed=!empty($in['confirmed'])&&in_array(strtolower((string)$in['confirmed']),['1','true','yes','on'],true);
$method=trim((string)($in['validationMethod']??'manual'));
$titleHint=trim((string)($in['title']??''));
$reason=trim((string)($in['reason']??''));
$typeHint=trim((string)($in['type']??''));

if(!$confirmed)json_response(['error'=>'Confirmation explicite du lien original requise.'],422);
if($profileId===''||$url===''||!filter_var($url,FILTER_VALIDATE_URL)||!p50_public_http_url($url)){
    json_response(['error'=>'Profil ou URL originale invalide.'],422);
}

function p50_nv_exact_content_path(string $platform,string $url): bool {
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
    $path=(string)(parse_url($url,PHP_URL_PATH)?:'/');
    $query=(string)(parse_url($url,PHP_URL_QUERY)?:'');
    if($platform==='YouTube')return str_contains($host,'youtu.be')||preg_match('#/(watch|shorts|live)/#i',$path)||str_contains($query,'v=');
    if($platform==='TikTok')return preg_match('#/@[^/]+/video/\d+#i',$path)===1;
    if($platform==='Instagram')return preg_match('#/(p|reel|tv)/[^/]+#i',$path)===1;
    if($platform==='Facebook')return preg_match('#/(videos|reel|posts|watch|share/(?:v|r|p))/#i',$path)===1||preg_match('/(?:^|&)(?:v|story_fbid|fbid)=/i',$query)===1||str_contains($host,'fb.watch');
    if($platform==='X')return preg_match('#/status/\d+#i',$path)===1;
    return trim($path,'/')!=='';
}

function p50_nv_content_id(string $platform,string $url): string {
    $path=(string)(parse_url($url,PHP_URL_PATH)?:'');
    $query=[];parse_str((string)(parse_url($url,PHP_URL_QUERY)?:''),$query);
    if($platform==='YouTube'){
        if(!empty($query['v']))return (string)$query['v'];
        if(preg_match('#/(?:shorts|live|embed)/([A-Za-z0-9_-]{6,})#',$path,$m))return $m[1];
        if(str_contains(strtolower((string)(parse_url($url,PHP_URL_HOST)?:'')),'youtu.be'))return trim($path,'/');
    }
    if($platform==='TikTok'&&preg_match('#/video/(\d+)#',$path,$m))return $m[1];
    if($platform==='Instagram'&&preg_match('#/(?:p|reel|tv)/([^/?#]+)#',$path,$m))return $m[1];
    if($platform==='X'&&preg_match('#/status/(\d+)#',$path,$m))return $m[1];
    if($platform==='Facebook'){
        if(!empty($query['v']))return (string)$query['v'];
        if(preg_match('#/(?:videos|reel)/([1-9]\d{5,})#',$path,$m))return $m[1];
    }
    return '';
}

function p50_nv_preview(string $url): array {
    $platform=p50_platform($url);
    if(!p50_nv_exact_content_path($platform,$url)){
        throw new InvalidArgumentException('Ce lien semble pointer vers un profil ou une page générale. Colle le lien exact de la vidéo, publication ou article.');
    }
    $title='';$author='';$thumbnail='';$canonical=$url;$source='Métadonnées publiques';$blocked=false;$thumbnailTrusted=false;$publishedAt=null;
    if($platform==='YouTube'){
        $o=p50_json_get('https://www.youtube.com/oembed?format=json&url='.rawurlencode($url),15);
        if($o){$title=(string)($o['title']??'');$author=(string)($o['author_name']??'');$thumbnail=(string)($o['thumbnail_url']??'');$source='YouTube oEmbed';$thumbnailTrusted=$thumbnail!=='';}
        if($thumbnail===''){
            $id=p50_nv_content_id('YouTube',$url);
            if($id!==''){$thumbnail='https://i.ytimg.com/vi/'.rawurlencode($id).'/hqdefault.jpg';$source='Miniature YouTube directe';$thumbnailTrusted=true;}
        }
    }elseif($platform==='TikTok'){
        $o=p50_json_get('https://www.tiktok.com/oembed?url='.rawurlencode($url),15);
        if($o){$title=(string)($o['title']??'');$author=(string)($o['author_name']??'');$thumbnail=(string)($o['thumbnail_url']??'');$source='TikTok oEmbed';$thumbnailTrusted=$thumbnail!=='';}
    }
    if($title===''||$thumbnail===''){
        $r=p50_http_fetch($url,15,'text/html,*/*;q=0.7');
        $blocked=in_array((int)$r['status'],[403,429],true);
        if($r['body']!==''){
            $m=p50_page_metadata($r['body'],$r['finalUrl']?:$url);
            if($title==='')$title=(string)($m['title']??'');
            if($thumbnail===''){$thumbnail=(string)($m['image']??'');if($thumbnail!=='')$thumbnailTrusted=true;}
            if(!empty($m['canonical'])){
                $candidate=(string)$m['canonical'];
                if(p50_platform($candidate)===$platform&&p50_nv_exact_content_path($platform,$candidate))$canonical=$candidate;
            }
            if(preg_match('/property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)/i',$r['body'],$mm)||preg_match('/itemprop=["\']uploadDate["\'][^>]+content=["\']([^"\']+)/i',$r['body'],$mm)||preg_match('/"uploadDate"\s*:\s*"([^"]+)"/',$r['body'],$mm)){
                try{$publishedAt=(new DateTimeImmutable($mm[1]))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);}catch(Throwable){}
            }
            $source=$source==='Métadonnées publiques'?'Open Graph':$source.' + Open Graph';
        }
        if(!empty($r['finalUrl'])&&p50_platform($r['finalUrl'])===$platform&&p50_nv_exact_content_path($platform,$r['finalUrl']))$canonical=$r['finalUrl'];
    }
    return [
        'url'=>$url,'canonicalUrl'=>$canonical?:$url,'platform'=>$platform,'title'=>$title,'author'=>$author,
        'thumbnail'=>$thumbnail,'thumbnailTrusted'=>$thumbnailTrusted,'source'=>$source,'blocked'=>$blocked,
        'publishedAt'=>$publishedAt,'contentId'=>p50_nv_content_id($platform,$canonical?:$url),
    ];
}

try{$preview=p50_nv_preview($url);}catch(InvalidArgumentException $e){json_response(['error'=>$e->getMessage()],422);}catch(Throwable $e){json_response(['error'=>'Analyse du lien impossible.'],502);}

$canonical=(string)$preview['canonicalUrl'];
$platform=(string)$preview['platform'];
$isVideo=in_array($platform,['YouTube','TikTok','Instagram','Facebook','Snapchat'],true)||stripos($typeHint,'vidéo')!==false||stripos($typeHint,'video')!==false;
$contentType=$isVideo?(preg_match('#/(shorts|reel)/#i',$canonical)?'short':'video'):'post';
$title=$titleHint!==''?$titleHint:((string)$preview['title']!==''?(string)$preview['title']:($isVideo?'Vidéo validée':'Article validé'));

$metricBridge=['queued'=>false,'contentUpserted'=>false,'accountId'=>null,'contentId'=>null,'jobUuid'=>null,'error'=>null];
try{
    $pdo=db();
    p50_metrics_ensure_schema($pdo);
    $official=null;
    try{
        $stmt=$pdo->prepare("SELECT normalized_url,handle,confidence FROM p50_social_links WHERE profile_id=? AND platform=? AND status='verified' ORDER BY confidence DESC LIMIT 1");
        $stmt->execute([$profileId,$platform]);
        $official=$stmt->fetch()?:null;
    }catch(Throwable){}
    $accountUrl=$official?trim((string)$official['normalized_url']):'';
    if($accountUrl===''&&preg_match('#(https?://[^/]+/@[^/?#]+)#i',$canonical,$m))$accountUrl=$m[1];
    if($accountUrl===''&&$platform==='YouTube'&&preg_match('#(https?://www\.youtube\.com/(?:@[^/?#]+|channel/[^/?#]+))#i',$canonical,$m))$accountUrl=$m[1];
    $handle=$official?trim((string)($official['handle']??'')):'';
    if($handle===''&&!empty($preview['author']))$handle=(string)$preview['author'];
    $account=p50_metrics_upsert_account($pdo,[
        'profileId'=>$profileId,'platform'=>$platform,'canonicalUrl'=>$accountUrl!==''?$accountUrl:$canonical,
        'handle'=>$handle,'confidence'=>max(80,(int)($official['confidence']??80)),
        'sourceType'=>'actualite_validated','observedAt'=>gmdate('c'),
        'provenance'=>['source'=>'news-validate','validatedBy'=>(string)$user['id'],'method'=>$method],
    ]);
    $content=p50_metrics_upsert_content($pdo,[
        'accountId'=>(int)$account['id'],
        'platformContentId'=>$preview['contentId']!==''?(string)$preview['contentId']:null,
        'contentType'=>$contentType,
        'canonicalUrl'=>$canonical,
        'title'=>$title,
        'publishedAt'=>$preview['publishedAt'],
        'confidence'=>90,
        'sourceType'=>'actualite_validated',
        'observedAt'=>gmdate('c'),
        'metadata'=>['validationMethod'=>$method,'previewSource'=>$preview['source']],
        'provenance'=>['source'=>'news-validate','validatedBy'=>(string)$user['id'],'profileId'=>$profileId],
    ]);
    $job=p50_mo_enqueue_profile($pdo,$profileId,$platform,'p1',[
        'priorityOverride'=>35,
        'reason'=>'actualite_validated',
        'contentLimit'=>8,
        'dispatchId'=>'news-validate-'.substr(hash('sha256',$profileId.'|'.$canonical),0,16),
    ]);
    $metricBridge=[
        'queued'=>true,
        'contentUpserted'=>!empty($content['created'])||!empty($content['id']),
        'accountId'=>(int)$account['id'],
        'contentId'=>(int)$content['id'],
        'jobUuid'=>(string)($job['jobUuid']??''),
        'jobCreated'=>!empty($job['created']),
        'error'=>null,
    ];
}catch(Throwable $e){
    $metricBridge['error']=substr($e->getMessage(),0,180);
}

json_response([
    'ok'=>true,
    'validContent'=>true,
    'profileId'=>$profileId,
    'confirmed'=>true,
    'validationMethod'=>$method,
    'validatedBy'=>(string)$user['id'],
    'validatedAt'=>gmdate(DATE_ATOM),
    'url'=>$url,
    'canonicalUrl'=>$canonical,
    'platform'=>$platform,
    'title'=>$title,
    'author'=>(string)$preview['author'],
    'thumbnail'=>(string)$preview['thumbnail'],
    'thumbnailTrusted'=>(bool)$preview['thumbnailTrusted'],
    'source'=>(string)$preview['source'],
    'blocked'=>(bool)$preview['blocked'],
    'publishedAt'=>$preview['publishedAt'],
    'contentId'=>(string)$preview['contentId'],
    'type'=>$typeHint!==''?$typeHint:($isVideo?'Vidéo':'Article'),
    'reason'=>$reason!==''?$reason:($isVideo?'Vidéo récente validée avec confirmation explicite.':'Article récent validé avec confirmation explicite.'),
    'metricBridge'=>$metricBridge,
    'message'=>$metricBridge['queued']
        ?'Lien validé et capture métrique mise en file.'
        :'Lien validé. Capture métrique non mise en file'.($metricBridge['error']?(' : '.$metricBridge['error']):'.'),
]);
