<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/http-tools.php';
require_method('POST');
$user = auth_user();
require_role($user, 'owner', 'admin');
$in = json_input();
$name = trim((string)($in['name'] ?? ''));
$days = max(1,min(90,(int)($in['days'] ?? 15)));
if ($name === '') json_response(['error'=>'Nom manquant.'],422);

function p50_news_date(string $raw): string {
    $raw=trim($raw);if($raw==='')return '';
    try{return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable){return $raw;}
}
function p50_news_gdelt(string $name,int $days): array {
    $end=new DateTimeImmutable('now',new DateTimeZone('UTC'));$start=$end->modify('-'.$days.' days');
    $url='https://api.gdeltproject.org/api/v2/doc/doc?'.http_build_query(['query'=>'"'.$name.'"','mode'=>'ArtList','maxrecords'=>30,'format'=>'json','sort'=>'HybridRel','startdatetime'=>$start->format('YmdHis'),'enddatetime'=>$end->format('YmdHis')]);
    $r=p50_http_fetch($url,22,'application/json,*/*;q=0.7');
    if(!$r['ok'])return ['articles'=>[],'error'=>'GDELT HTTP '.$r['status'],'url'=>$url];
    $data=json_decode($r['body'],true);if(!is_array($data))return ['articles'=>[],'error'=>'Réponse GDELT invalide','url'=>$url];
    $out=[];foreach((array)($data['articles']??[]) as $a){$u=(string)($a['url']??'');if(!filter_var($u,FILTER_VALIDATE_URL))continue;$out[]=['title'=>(string)($a['title']??''),'url'=>$u,'domain'=>(string)($a['domain']??parse_url($u,PHP_URL_HOST)??''),'date'=>p50_news_date((string)($a['seendate']??'')),'language'=>(string)($a['language']??''),'sourceCountry'=>(string)($a['sourcecountry']??''),'image'=>(string)($a['socialimage']??''),'source'=>'GDELT'];}
    return ['articles'=>$out,'error'=>'','url'=>$url];
}
function p50_news_google_rss(string $name,int $days): array {
    if(!function_exists('simplexml_load_string'))return ['articles'=>[],'error'=>'Extension XML indisponible'];
    $query='"'.$name.'" when:'.$days.'d';$url='https://news.google.com/rss/search?'.http_build_query(['q'=>$query,'hl'=>'fr','gl'=>'CI','ceid'=>'CI:fr']);
    $r=p50_http_fetch($url,18,'application/rss+xml,application/xml,text/xml,*/*;q=0.5');if(!$r['ok'])return ['articles'=>[],'error'=>'Google News HTTP '.$r['status']];
    libxml_use_internal_errors(true);$xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);if(!$xml)return ['articles'=>[],'error'=>'Flux Google News invalide'];
    $out=[];foreach($xml->channel->item as $item){$u=trim((string)$item->link);if(!filter_var($u,FILTER_VALIDATE_URL))continue;$source=trim((string)$item->source);$out[]=['title'=>trim((string)$item->title),'url'=>$u,'domain'=>$source?:((string)(parse_url($u,PHP_URL_HOST)??'')),'date'=>p50_news_date((string)$item->pubDate),'language'=>'fr','sourceCountry'=>'','image'=>'','source'=>'Google News'];if(count($out)>=30)break;}
    return ['articles'=>$out,'error'=>''];
}

try{
    $gdelt=p50_news_gdelt($name,$days);$articles=$gdelt['articles'];$sources=['GDELT'];$warnings=[];
    if(!$articles){if(!empty($gdelt['error']))$warnings[]=$gdelt['error'];$google=p50_news_google_rss($name,$days);$articles=$google['articles'];$sources[]='Google News RSS';if(!$articles&&!empty($google['error']))$warnings[]=$google['error'];}
    $seen=[];$unique=[];foreach($articles as $a){$key=hash('sha256',strtolower(trim((string)$a['title'])).'|'.(string)$a['url']);if(isset($seen[$key]))continue;$seen[$key]=true;$unique[]=$a;}
    json_response(['ok'=>true,'name'=>$name,'days'=>$days,'articles'=>$unique,'source'=>implode(' + ',$sources),'warning'=>$warnings?('La source principale a rencontré un problème : '.implode(' · ',$warnings).'. Le formulaire manuel reste disponible.'):'','generatedAt'=>gmdate('c')]);
}catch(Throwable $e){
    error_log('PASS50 news-discover: '.$e->getMessage());
    json_response(['ok'=>true,'name'=>$name,'days'=>$days,'articles'=>[],'source'=>'indisponible','warning'=>'Les sources d’actualité sont momentanément indisponibles. Utilise le formulaire manuel pour valider le lien original.','generatedAt'=>gmdate('c')]);
}
