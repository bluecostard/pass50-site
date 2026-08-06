<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
$user=auth_user();
require_role($user,'owner','admin');
require_method('POST');
set_time_limit(120);

$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$name=trim((string)($in['name']??''));
$handle=ltrim(trim((string)($in['handle']??'')),'@');
$days=max(1,min(90,(int)($in['days']??30)));
if($profileId===''||$name==='')json_response(['error'=>'Fiche et nom requis.'],422);

function p50_news_v2_clean(string $value): string {
    return trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
}
function p50_news_v2_item(string $title,string $url,string $date,string $source,string $kind='article',string $image='',int $confidence=0): ?array {
    $title=p50_news_v2_clean($title);$url=trim($url);
    if($title===''||$url===''||!filter_var($url,FILTER_VALIDATE_URL)||!p50_public_http_url($url))return null;
    $platform=p50_platform($url);
    if($kind==='video'||p50_de_is_exact_social_content($platform,$url))$kind='video';
    return ['kind'=>$kind,'type'=>$kind==='video'?'Vidéo':'Article','title'=>$title,'url'=>$url,'image'=>$image,
        'domain'=>(string)(parse_url($url,PHP_URL_HOST)?:$source),'platform'=>$platform,'date'=>$date,'language'=>'fr','source'=>$source,'confidence'=>$confidence];
}
function p50_news_v2_rss(string $url,string $source,string $name,string $handle=''): array {
    $response=p50_http_fetch($url,20,'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.5');
    if(!$response['ok']||$response['body']===''||!function_exists('simplexml_load_string'))return ['items'=>[],'status'=>(int)$response['status'],'error'=>$response['error']?:$response['collectionStatus']];
    libxml_use_internal_errors(true);$xml=simplexml_load_string($response['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if(!$xml)return ['items'=>[],'status'=>(int)$response['status'],'error'=>'XML invalide'];
    $entries=[];
    if(isset($xml->channel->item))foreach($xml->channel->item as $entry)$entries[]=$entry;
    elseif(isset($xml->entry))foreach($xml->entry as $entry)$entries[]=$entry;
    $items=[];
    foreach($entries as $entry){
        $title=p50_news_v2_clean((string)($entry->title??''));
        $link=trim((string)($entry->link??''));
        if($link===''&&isset($entry->link['href']))$link=trim((string)$entry->link['href']);
        $date=trim((string)($entry->pubDate??$entry->published??$entry->updated??''));
        $score=p50_name_score($title.' '.$link,$name,$handle);
        if($score<45)continue;
        $kind=p50_de_is_exact_social_content(p50_platform($link),$link)?'video':'article';
        $item=p50_news_v2_item($title,$link,$date,$source,$kind,'',$score);
        if($item)$items[]=$item;
    }
    return ['items'=>$items,'status'=>(int)$response['status'],'error'=>''];
}
function p50_news_v2_dedupe(array $items): array {
    usort($items,static fn($a,$b)=>((int)($b['confidence']??0)<=> (int)($a['confidence']??0))?:strcmp((string)($b['date']??''),(string)($a['date']??'')));
    $seen=[];$out=[];
    foreach($items as $item){$key=strtolower(rtrim((string)$item['url'],'/'));if($key===''||isset($seen[$key]))continue;$seen[$key]=1;$out[]=$item;if(count($out)>=30)break;}
    return $out;
}

p50_de_ensure_schema();p50_de_sync_registry_from_state();
$results=[];$diagnostics=[];
foreach(p50_de_activity_events($profileId,false,80) as $event){
    $published=(string)($event['published_at']??'');
    if($published!==''){try{if((new DateTimeImmutable($published))<(new DateTimeImmutable('-'.$days.' days')))continue;}catch(Throwable){}}
    $item=p50_news_v2_item((string)($event['title']??'Contenu récent de '.$name),(string)($event['url']??''),$published,(string)($event['platform']??'Collecte PASS50'),'article','',(int)($event['confidence']??0));
    if($item)$results[]=$item;
}

$queries=array_values(array_unique(array_filter([
    '"'.$name.'" when:'.$days.'d',
    $name.' Côte d\'Ivoire when:'.$days.'d',
    $handle!==''?'"@'.$handle.'" when:'.$days.'d':'',
])));
foreach($queries as $query){
    $google='https://news.google.com/rss/search?q='.rawurlencode($query).'&hl=fr&gl=CI&ceid=CI:fr';
    $g=p50_news_v2_rss($google,'Google News',$name,$handle);$results=array_merge($results,$g['items']);$diagnostics[]=['source'=>'Google News','query'=>$query,'status'=>$g['status'],'count'=>count($g['items']),'error'=>$g['error']];
    $bing='https://www.bing.com/search?format=rss&q='.rawurlencode(str_replace(' when:'.$days.'d','',$query));
    $b=p50_news_v2_rss($bing,'Bing',$name,$handle);$results=array_merge($results,$b['items']);$diagnostics[]=['source'=>'Bing','query'=>$query,'status'=>$b['status'],'count'=>count($b['items']),'error'=>$b['error']];
}

try{
    $profiles=p50_de_registry_profiles($profileId,1,0,false);
    if($profiles){
        $profile=$profiles[0];
        try{p50_de_collect_youtube_activity($profile);}catch(Throwable $e){$diagnostics[]=['source'=>'YouTube officiel','status'=>0,'count'=>0,'error'=>$e->getMessage()];}
        try{p50_de_collect_social_activity($profile);}catch(Throwable $e){$diagnostics[]=['source'=>'Réseaux officiels','status'=>0,'count'=>0,'error'=>$e->getMessage()];}
        foreach(p50_de_activity_events($profileId,false,80) as $event){
            $item=p50_news_v2_item((string)($event['title']??'Contenu récent de '.$name),(string)($event['url']??''),(string)($event['published_at']??''),(string)($event['platform']??'Collecte PASS50'),'article','',(int)($event['confidence']??0));
            if($item)$results[]=$item;
        }
    }
}catch(Throwable $e){$diagnostics[]=['source'=>'Collecte officielle','status'=>0,'count'=>0,'error'=>$e->getMessage()];}

$results=p50_news_v2_dedupe($results);
$failures=array_values(array_filter($diagnostics,static fn($d)=>!empty($d['error'])||((int)$d['status']>=400)));
$message=$results?count($results).' résultat(s) exploitable(s).':'Aucun résultat exploitable pour cette période.';
json_response(['ok'=>true,'version'=>'NEWS-DISCOVER-V2.0','profileId'=>$profileId,'name'=>$name,'days'=>$days,'results'=>$results,'articles'=>$results,
    'source'=>'Comptes officiels PASS50 + Google News + Bing','message'=>$message,'diagnostics'=>$diagnostics,
    'warning'=>$failures?count($failures).' source(s) indisponible(s) ou en erreur. Consulte le diagnostic.':'']);
