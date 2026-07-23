<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
$user=auth_user();
require_role($user,'owner','admin');
require_method('POST');
set_time_limit(150);

$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$name=trim((string)($in['name']??''));
$handle=trim((string)($in['handle']??''));
$days=max(1,min(90,(int)($in['days']??15)));
if($name==='')json_response(['error'=>'Nom de l’influenceur requis.'],422);

function p50_news_clean_title(string $title): string {
    return trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($title),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
}

function p50_news_item(array $row,string $kind='article',int $priority=10): ?array {
    $url=trim((string)($row['url']??''));$title=p50_news_clean_title((string)($row['title']??''));
    if($url===''||$title===''||!filter_var($url,FILTER_VALIDATE_URL)||!p50_public_http_url($url))return null;
    $platform=(string)($row['platform']??p50_platform($url));
    $isVideo=$kind==='video'||p50_de_is_exact_social_content($platform,$url);
    if($isVideo)$kind='video';
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)?:''));
    return [
        'kind'=>$kind,
        'type'=>$kind==='video'?'Vidéo':'Article',
        'title'=>$title,
        'url'=>$url,
        'image'=>trim((string)($row['image']??$row['socialimage']??'')),
        'domain'=>trim((string)($row['domain']??$host)),
        'platform'=>$platform==='Web'?'Web':$platform,
        'date'=>trim((string)($row['date']??$row['seendate']??'')),
        'language'=>trim((string)($row['language']??'fr')),
        'source'=>trim((string)($row['source']??'')),
        'priority'=>$priority,
        'confidence'=>(int)($row['confidence']??0),
    ];
}

function p50_news_dedupe_sort(array $items,int $limit=30): array {
    usort($items,static function($a,$b){
        $p=((int)($a['priority']??10))<=>((int)($b['priority']??10));if($p!==0)return $p;
        return strcmp((string)($b['date']??''),(string)($a['date']??''));
    });
    $seen=[];$out=[];
    foreach($items as $item){
        if(!is_array($item))continue;$url=strtolower(rtrim((string)($item['url']??''),'/'));
        if($url===''||isset($seen[$url]))continue;$seen[$url]=true;unset($item['priority']);$out[]=$item;
        if(count($out)>=$limit)break;
    }
    return $out;
}

function p50_news_rss_items(string $url,string $source,int $priority,bool $videoOnly=false): array {
    $out=[];$r=p50_http_fetch($url,18,'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.5');
    if(!$r['ok']||$r['body']===''||!function_exists('simplexml_load_string'))return [];
    libxml_use_internal_errors(true);$xml=simplexml_load_string($r['body'],'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);
    if(!$xml||!isset($xml->channel->item))return [];
    foreach($xml->channel->item as $entry){
        $title=trim((string)$entry->title);$link=trim((string)$entry->link);$pub=trim((string)$entry->pubDate);
        if($link===''||$title==='')continue;$platform=p50_platform($link);$isVideo=p50_de_is_exact_social_content($platform,$link);
        if($videoOnly&&!$isVideo)continue;
        $date='';if($pub!==''){try{$date=(new DateTimeImmutable($pub))->format('Y-m-d H:i');}catch(Throwable){}}
        $sourceNode=$entry->source??null;$domain=$sourceNode?trim((string)$sourceNode):(string)(parse_url($link,PHP_URL_HOST)?:$source);
        $item=p50_news_item(['title'=>$title,'url'=>$link,'domain'=>$domain,'platform'=>$platform,'date'=>$date,'source'=>$source],$isVideo?'video':'article',$priority);
        if($item)$out[]=$item;
    }
    return $out;
}

$results=[];$warnings=[];$visitedSocial=[];
p50_de_ensure_schema();
p50_de_sync_registry_from_state();

// 1) Réseaux officiels et flux vidéo déjà connus du moteur.
if($profileId!==''){
    $profiles=p50_de_registry_profiles($profileId,1,0,false);
    if($profiles){
        $profile=$profiles[0];
        try{$yt=p50_de_collect_youtube_activity($profile);$visitedSocial['YouTube']=$yt;}catch(Throwable $e){$warnings[]='YouTube indisponible.';}
        try{$social=p50_de_collect_social_activity($profile);$visitedSocial['autres']=$social;if(!empty($social['blocked']))$warnings[]='Certains réseaux bloquent la visite automatique : '.implode(', ',(array)$social['blocked']).'.';}catch(Throwable $e){$warnings[]='Visite de certains réseaux indisponible.';}
        foreach(p50_de_activity_events($profileId,false,60) as $event){
            $published=(string)($event['published_at']??'');
            if($published!==''){
                try{if((new DateTimeImmutable($published))<(new DateTimeImmutable('-'.$days.' days')))continue;}catch(Throwable){}
            }
            $platform=(string)($event['platform']??'Web');$eventType=strtolower((string)($event['event_type']??''));
            $kind=in_array($eventType,['video','reel','short','live'],true)||in_array($platform,['YouTube','TikTok','Instagram','Facebook','Snapchat'],true)?'video':'article';
            $item=p50_news_item([
                'title'=>(string)($event['title']??'Contenu récent de '.$name),
                'url'=>(string)($event['url']??''),
                'platform'=>$platform,
                'date'=>$published,
                'source'=>$platform.' officiel',
                'confidence'=>(int)($event['confidence']??0),
            ],$kind,$kind==='video'?0:6);
            if($item)$results[]=$item;
        }
    }
}

// 2) Recherche sociale publique : davantage de vidéos que d'articles.
$videoQueries=[
    '"'.$name.'" (site:youtube.com/watch OR site:youtube.com/shorts)',
    '"'.$name.'" (site:tiktok.com OR site:instagram.com/reel OR site:facebook.com/reel OR site:facebook.com/videos)',
];
foreach($videoQueries as $query){
    try{
        $rss='https://www.bing.com/search?format=rss&q='.rawurlencode($query);
        $results=array_merge($results,p50_news_rss_items($rss,'Bing Vidéos',1,true));
    }catch(Throwable){$warnings[]='Recherche vidéo Bing indisponible.';}
}

// 3) Articles, volontairement limités et placés après les vidéos.
$articles=[];
try{
    $query='"'.str_replace('"','',$name).'"';
    $gdelt='https://api.gdeltproject.org/api/v2/doc/doc?query='.rawurlencode($query).'&mode=ArtList&maxrecords=15&format=json&sort=datedesc&timespan='.$days.'d';
    $r=p50_http_fetch($gdelt,18,'application/json,*/*;q=0.7');
    if($r['ok']){
        $data=json_decode($r['body'],true);
        foreach((array)($data['articles']??[]) as $row){
            $item=p50_news_item(is_array($row)?$row:[],'article',10);if($item){$item['source']='GDELT';$articles[]=$item;}
        }
    }else $warnings[]='GDELT indisponible (HTTP '.(int)$r['status'].').';
}catch(Throwable){$warnings[]='GDELT indisponible.';}

if(count($articles)<5){
    try{
        $rss='https://news.google.com/rss/search?q='.rawurlencode('"'.$name.'" when:'.$days.'d').'&hl=fr&gl=CI&ceid=CI:fr';
        $articles=array_merge($articles,p50_news_rss_items($rss,'Google News',11,false));
    }catch(Throwable){$warnings[]='Google News indisponible.';}
}
$results=array_merge($results,array_slice($articles,0,10));
$results=p50_news_dedupe_sort($results,30);
$videoCount=count(array_filter($results,static fn($x)=>(string)($x['kind']??'')==='video'));
$articleCount=count($results)-$videoCount;

json_response([
    'ok'=>true,
    'name'=>$name,
    'profileId'=>$profileId,
    'days'=>$days,
    'source'=>'Réseaux officiels + Bing Vidéos + GDELT/Google News',
    'articles'=>$results,
    'results'=>$results,
    'videoCount'=>$videoCount,
    'articleCount'=>$articleCount,
    'socialVisit'=>$visitedSocial,
    'warning'=>implode(' ',array_values(array_unique($warnings))),
    'message'=>$results?count($results).' résultat(s), vidéos affichées en premier.':'Aucune vidéo ni actualité récente trouvée pour cette période.',
]);
