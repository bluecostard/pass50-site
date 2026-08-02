<?php
declare(strict_types=1);

const P50_LIVE_V4_LOGIC_REVISION = 'LIVE-RADAR-CONTINUOUS-MAX-2026-08-02-1';
const P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS = 43200;

function p50_live_v4_unescape(string $value): string {
    return html_entity_decode(str_replace(['\\u0026','\\u003d','\\/'],['&','=','/'],$value),ENT_QUOTES|ENT_HTML5,'UTF-8');
}

function p50_live_v4_video_id(string $url,string $html=''): string {
    $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));$path=(string)($parts['path']??'');
    if(str_contains($host,'youtu.be'))return trim($path,'/');
    parse_str((string)($parts['query']??''),$query);if(!empty($query['v']))return (string)$query['v'];
    if(preg_match('#/(?:shorts|embed|live)/([A-Za-z0-9_-]{6,})#',$path,$m))return $m[1];
    foreach(['/"videoId"\s*:\s*"([A-Za-z0-9_-]{6,})"/','/youtube\.com\/watch\?v=([A-Za-z0-9_-]{6,})/'] as $pattern)if(preg_match($pattern,$html,$m))return $m[1];
    return '';
}

function p50_live_v4_tiktok_room_id(string $body): string {
    $body=p50_live_v4_unescape($body);
    foreach([
        '/"roomId"\s*:\s*"?([1-9]\d{5,})"?/i','/"room_id"\s*:\s*"?([1-9]\d{5,})"?/i','/"liveRoomId"\s*:\s*"?([1-9]\d{5,})"?/i',
        '/"webcastRoomId"\s*:\s*"?([1-9]\d{5,})"?/i','/"LiveRoom"\s*:\s*\{.{0,800}?"id"\s*:\s*"?([1-9]\d{5,})"?/is','/[?&]room_id=([1-9]\d{5,})/i',
    ] as $pattern)if(preg_match($pattern,$body,$m))return (string)$m[1];
    return '';
}

function p50_live_v4_tiktok_room_timestamp(string $roomId): ?int {
    if(!preg_match('/^[1-9]\d{15,18}$/',$roomId))return null;
    $value=(int)$roomId;if($value<=0)return null;
    $timestamp=intdiv($value,4294967296);$now=time();
    if($timestamp<1577836800||$timestamp>$now+600)return null;
    return $timestamp;
}

function p50_live_v4_tiktok_room_is_fresh(string $roomId,int $maxAge=P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS): bool {
    $timestamp=p50_live_v4_tiktok_room_timestamp($roomId);
    return $timestamp!==null&&time()-$timestamp>=-600&&time()-$timestamp<=max(900,$maxAge);
}

function p50_live_v4_viewers(string $body): ?int {
    foreach(['/"concurrentViewers"\s*:\s*"?(\d+)"?/i','/"user_count"\s*:\s*"?(\d+)"?/i','/"viewerCount"\s*:\s*"?(\d+)"?/i','/"liveRoomUserCount"\s*:\s*"?(\d+)"?/i','/"roomUserCount"\s*:\s*"?(\d+)"?/i'] as $pattern)if(preg_match($pattern,$body,$m))return (int)$m[1];
    return null;
}

function p50_live_v4_block_page(string $body): bool {
    return (bool)preg_match('/captcha|verify to continue|security check|challenge-platform|access denied|temporarily blocked|unusual traffic|robot check/i',$body);
}

function p50_live_v4_parse_youtube(array $source,array $responses): array {
    $r=$responses['live']??[];$html=(string)($r['body']??'');$maxMs=(int)($r['timeMs']??0);
    if(empty($r['ok'])||$html==='')return ['state'=>'unknown','error'=>(string)($r['error']??('http_'.($r['status']??0))),'confidence'=>0,'responseMs'=>$maxMs];
    $base=(string)($r['finalUrl']??$source['url']);$meta=p50_page_metadata($html,$base);$videoId=p50_live_v4_video_id((string)($meta['canonical']?:$base),$html);
    $ended=(bool)preg_match('/"(?:endTimestamp|actualEndTime)"\s*:\s*"[^"]+"/i',$html)||(bool)preg_match('/itemprop=["\']endDate["\']/i',$html);
    $isLive=(bool)preg_match('/"isLiveNow"\s*:\s*true/i',$html)||(bool)preg_match('/itemprop=["\']isLiveBroadcast["\'][^>]+content=["\']True["\']/i',$html)||((bool)preg_match('/"isLiveContent"\s*:\s*true/i',$html)&&(bool)preg_match('/"playabilityStatus"\s*:\s*\{[^}]*"status"\s*:\s*"OK"/is',$html));
    $url=$videoId!==''?'https://www.youtube.com/watch?v='.$videoId:(string)($meta['canonical']?:$base);
    $title=trim((string)($meta['title']??''));$title=preg_replace('/\s*-\s*YouTube\s*$/iu','',$title)??$title;
    if($ended&&!$isLive)return ['state'=>'replay','error'=>'youtube_replay','confidence'=>99,'responseMs'=>$maxMs,'replay'=>['url'=>$url,'videoId'=>$videoId,'title'=>$title]];
    if(!$isLive)return ['state'=>'offline','error'=>'youtube_not_live','confidence'=>96,'responseMs'=>$maxMs];
    if($title==='')$title='Direct YouTube en cours';$started=null;
    if(preg_match('/"startTimestamp"\s*:\s*"([^"]+)"/',$html,$m)){try{$started=(new DateTimeImmutable(p50_live_v4_unescape($m[1])))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable){}}
    $thumb=(string)($meta['image']??'');if($thumb===''&&$videoId!=='')$thumb='https://i.ytimg.com/vi/'.rawurlencode($videoId).'/hqdefault.jpg';
    return ['state'=>'live','confidence'=>99,'responseMs'=>$maxMs,'live'=>['profileId'=>(string)$source['profile_id'],'platform'=>'YouTube','title'=>$title,'url'=>$url,'thumbnail'=>$thumb,'confidence'=>99,'startedAt'=>$started,'viewers'=>p50_live_v4_viewers($html),'metadata'=>['channelUrl'=>(string)$source['url'],'videoId'=>$videoId,'probe'=>'channel_live']]];
}

function p50_live_v4_tiktok_owner_mismatch(string $body,string $handle): bool {
    if(!preg_match('/"(?:uniqueId|unique_id|ownerHandle|owner_handle)"\s*:\s*"@?([^"]+)"/i',$body,$m))return false;
    return strcasecmp(trim($m[1],'@'),$handle)!==0;
}

function p50_live_v4_tiktok_owner_match(string $body,string $handle): bool {
    if($handle===''||!preg_match_all('/"(?:uniqueId|unique_id|ownerHandle|owner_handle)"\s*:\s*"@?([^"]+)"/i',$body,$matches))return false;
    foreach($matches[1] as $candidate)if(strcasecmp(trim((string)$candidate,'@'),$handle)===0)return true;
    return false;
}

function p50_live_v4_tiktok_ended_signal(string $body): bool {
    $body=p50_live_v4_unescape($body);
    $textEnd=(bool)preg_match('/\b(?:(?:ce|le|this)\s+)?live\s+(?:est\s+|is\s+|has\s+)?(?:termin(?:é|ée|e|ee|és|ées|es)|ended|finished|over)\b|\b(?:direct|diffusion|broadcast|livestream)\s+(?:est\s+|is\s+|has\s+)?(?:termin(?:é|ée|e|ee|és|ées|es)|ended|finished|over)\b|\bn[’\']est\s+plus\s+en\s+direct\b|\bnot\s+currently\s+live\b|\broom\s+not\s+found\b/iu',$body);
    $structured=(bool)preg_match('/"(?:liveStatus|live_status)"\s*:\s*4(?:\D|$)|"(?:isLive|is_live|isLiveStreaming)"\s*:\s*false|"(?:broadcast_status|roomStatus|room_status)"\s*:\s*"?(?:4|ended|finished|replay|offline)"?/i',$body);
    return $textEnd||$structured;
}

function p50_live_v4_tiktok_probe_family(string $label): string {
    return in_array($label,['api','api_basic'],true)?'api':'html';
}

function p50_live_v4_parse_tiktok(array $source,array $responses): array {
    $identity=p50_live_v4_identity('TikTok',(string)$source['url']);$errors=[];$maxMs=0;$blocked=0;$positive=[];$bodies=[];$roomEvidence=[];$endedLabels=[];
    foreach($responses as $label=>$r){
        $maxMs=max($maxMs,(int)($r['timeMs']??0));
        if(empty($r['ok'])){$errors[]=$label.':'.((string)($r['error']??'')?:('http_'.($r['status']??0)));continue;}
        $body=p50_live_v4_unescape((string)($r['body']??''));$bodies[$label]=$body;
        if($body===''||p50_live_v4_block_page($body)){$blocked++;continue;}
        if(p50_live_v4_tiktok_owner_mismatch($body,(string)$identity['handle']))continue;
        $isApi=in_array($label,['api','api_basic'],true);$isLiveProbe=in_array($label,['api','api_basic','live','mobile_live','embed'],true);$json=$isApi?json_decode($body,true):null;
        $roomId=p50_live_v4_tiktok_room_id($body);$apiStatusActive=$isApi&&$json!==null&&(bool)preg_match('/"status"\s*:\s*2(?:\D|$)/i',$body);
        $apiLiveStructure=$isApi&&$json!==null&&$roomId!==''&&(bool)preg_match('/"LiveRoom"\s*:|"webcastRoomId"\s*:|"liveRoomId"\s*:/i',$body);
        $strictApiActive=$apiStatusActive&&$roomId!==''&&p50_live_v4_tiktok_owner_match($body,(string)$identity['handle']);
        $freshApiActive=$isApi&&$json!==null&&$roomId!==''&&p50_live_v4_tiktok_room_is_fresh($roomId)&&($apiStatusActive||$apiLiveStructure);
        $currentApiActive=$strictApiActive||$freshApiActive;
        $explicitEnded=$isLiveProbe&&!$currentApiActive&&(p50_live_v4_tiktok_ended_signal($body)||($isApi&&$json!==null&&(bool)preg_match('/"status"\s*:\s*4(?:\D|$)/i',$body)));
        if($explicitEnded){$endedLabels[]=$label;continue;}
        $active=$currentApiActive
            ||(bool)preg_match('/"(?:liveStatus|live_status)"\s*:\s*2(?:\D|$)|"(?:isLive|is_live)"\s*:\s*true/i',$body)
            ||$apiStatusActive
            ||($roomId!==''&&(bool)preg_match('/"LiveRoom"\s*:|"webcastRoomId"\s*:|"liveRoomId"\s*:/i',$body));
        if($roomId!==''&&$active){
            $family=p50_live_v4_tiktok_probe_family((string)$label);$roomStartedAt=p50_live_v4_tiktok_room_timestamp($roomId);
            $positive[$label]=['roomId'=>$roomId,'family'=>$family,'strictApi'=>$strictApiActive,'freshApi'=>$freshApiActive,'apiLiveStructure'=>$apiLiveStructure,'roomStartedAt'=>$roomStartedAt,'body'=>$body];
            $roomEvidence[$roomId]=$roomEvidence[$roomId]??['api'=>[],'html'=>[],'strictApi'=>[],'freshApi'=>[],'apiLiveStructure'=>[],'roomStartedAt'=>$roomStartedAt];$roomEvidence[$roomId][$family][]=(string)$label;
            if($strictApiActive)$roomEvidence[$roomId]['strictApi'][]=(string)$label;
            if($freshApiActive)$roomEvidence[$roomId]['freshApi'][]=(string)$label;
            if($apiLiveStructure)$roomEvidence[$roomId]['apiLiveStructure'][]=(string)$label;
        }
    }
    if($positive){
        $roomId='';$confirmed=false;$strictApi=false;$freshApi=false;$crossFamily=false;$bestRank=-1;$bestTotal=-1;
        foreach($roomEvidence as $candidate=>$families){
            $apiCount=count($families['api']);$htmlCount=count($families['html']);$strictCount=count($families['strictApi']);$freshCount=count($families['freshApi']);$cross=$apiCount>0&&$htmlCount>0;$candidateConfirmed=$strictCount>0||$freshCount>0||$cross;$rank=$strictCount>0?3:($cross?2:($freshCount>0?1:0));$total=$apiCount+$htmlCount;
            if($rank>$bestRank||($rank===$bestRank&&$candidateConfirmed&&!$confirmed)||($rank===$bestRank&&$candidateConfirmed===$confirmed&&$total>$bestTotal)){$roomId=(string)$candidate;$strictApi=$strictCount>0;$freshApi=$freshCount>0;$crossFamily=$cross;$confirmed=$candidateConfirmed;$bestRank=$rank;$bestTotal=$total;}
        }
        if(!$confirmed&&$endedLabels)return ['state'=>'offline','error'=>'tiktok_live_ended','confidence'=>99,'responseMs'=>$maxMs,'evidence'=>['ended'=>$endedLabels,'blocked'=>$blocked,'positive'=>array_keys($positive),'rooms'=>$roomEvidence]];
        $state=$confirmed?'live':'probable';$confidence=$strictApi?99:($crossFamily?98:($freshApi?96:78));
        $best='';$bestUrl=$identity['liveUrl'];foreach(['live','mobile_live','embed','profile','api','api_basic'] as $label)if(!empty($bodies[$label])){$best=$bodies[$label];$bestUrl=(string)($responses[$label]['finalUrl']??$bestUrl);break;}
        $meta=p50_page_metadata($best,$bestUrl);$title=trim((string)($meta['title']??''));$title=preg_replace('/\s*\|\s*TikTok\s*$/iu','',$title)??$title;
        if($title===''||preg_match('/^(TikTok|Make Your Day)$/iu',$title))$title=trim((string)($source['public_name']??''));
        if($title==='')$title='Direct TikTok détecté';elseif(!preg_match('/\b(direct|live)\b/iu',$title))$title.=' est en direct';
        $families=$roomEvidence[$roomId]??['api'=>[],'html'=>[],'strictApi'=>[],'freshApi'=>[],'apiLiveStructure'=>[],'roomStartedAt'=>null];$startedTimestamp=(int)($families['roomStartedAt']??0);$startedAt=$startedTimestamp>0?gmdate('Y-m-d H:i:s',$startedTimestamp):null;
        $live=['profileId'=>(string)$source['profile_id'],'platform'=>'TikTok','title'=>$title,'url'=>$identity['liveUrl'],'thumbnail'=>(string)($meta['image']??''),'confidence'=>$confidence,'startedAt'=>$startedAt,'viewers'=>p50_live_v4_viewers(implode("\n",$bodies)),'metadata'=>['profileUrl'=>$identity['profileUrl'],'handle'=>'@'.$identity['handle'],'roomId'=>$roomId,'roomStartedAt'=>$startedAt,'roomAgeSeconds'=>$startedTimestamp>0?max(0,time()-$startedTimestamp):null,'probeLabels'=>array_keys($positive),'proofFamilies'=>['api'=>$families['api'],'html'=>$families['html']],'strictApiLabels'=>$families['strictApi'],'freshApiLabels'=>$families['freshApi'],'apiLiveStructureLabels'=>$families['apiLiveStructure'],'classification'=>$state]];
        return ['state'=>$state,'confidence'=>$confidence,'responseMs'=>$maxMs,'error'=>$state==='probable'?'tiktok_confirmation_incomplete':'','live'=>$live,'evidence'=>['positive'=>array_keys($positive),'ended'=>$endedLabels,'blocked'=>$blocked,'rooms'=>$roomEvidence,'freshRoomSeconds'=>P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS]];
    }
    if($endedLabels)return ['state'=>'offline','error'=>'tiktok_live_ended','confidence'=>99,'responseMs'=>$maxMs,'evidence'=>['ended'=>$endedLabels,'blocked'=>$blocked,'positive'=>[],'rooms'=>[]]];
    return ['state'=>'unknown','error'=>$blocked>0?'tiktok_blocked_or_challenged':($errors?implode(';',$errors):'tiktok_no_live_signal'),'confidence'=>0,'responseMs'=>$maxMs,'evidence'=>['ended'=>[],'blocked'=>$blocked,'positive'=>[]]];
}

function p50_live_v4_parse_instagram(array $source,array $responses): array {
    $identity=p50_live_v4_identity('Instagram',(string)$source['url']);$combined='';$errors=[];$maxMs=0;$ok=0;$blocked=0;$probeLabels=[];
    foreach($responses as $label=>$r){
        $maxMs=max($maxMs,(int)($r['timeMs']??0));
        if(empty($r['ok'])){$errors[]=$label.':http_'.($r['status']??0);continue;}
        $body=(string)($r['body']??'');
        if($body===''||p50_live_v4_block_page($body)){$blocked++;continue;}
        $ok++;$probeLabels[]=(string)$label;$combined.="\n".$body;
    }
    if($ok===0)return ['state'=>'unknown','error'=>$blocked>0?'instagram_blocked_or_challenged':implode(';',$errors),'confidence'=>0,'responseMs'=>$maxMs];
    $live=(bool)preg_match('/"(?:is_live_broadcast|isLiveBroadcast|is_live|isLive|broadcasting_content)"\s*:\s*true/i',$combined)
        ||(bool)preg_match('/"broadcast_status"\s*:\s*"(?:active|live)"/i',$combined)
        ||(bool)preg_match('/"live_broadcast_id"\s*:\s*"?[1-9]\d{5,}"?/i',$combined)
        ||(bool)preg_match('/"live_status"\s*:\s*1(?:\D|$)/i',$combined)
        ||(bool)preg_match('/"media_product_type"\s*:\s*"LIVE"/i',$combined);
    $offline=(bool)preg_match('/"(?:is_live_broadcast|isLiveBroadcast|is_live|isLive|broadcasting_content)"\s*:\s*false/i',$combined)
        ||(bool)preg_match('/"broadcast_status"\s*:\s*"(?:ended|archived|vod|finished)"/i',$combined)
        ||(bool)preg_match('/"live_status"\s*:\s*0(?:\D|$)/i',$combined);
    if($live){
        $meta=p50_page_metadata($combined,$identity['profileUrl']);
        $confidence=!empty($responses['web_profile']['ok'])?96:94;
        return ['state'=>'live','confidence'=>$confidence,'responseMs'=>$maxMs,'live'=>[
            'profileId'=>(string)$source['profile_id'],'platform'=>'Instagram',
            'title'=>trim((string)($source['public_name']??'Instagram')).' est en direct',
            'url'=>$identity['profileUrl'],'thumbnail'=>(string)($meta['image']??''),'confidence'=>$confidence,'startedAt'=>null,
            'viewers'=>p50_live_v4_viewers($combined),
            'metadata'=>['profileUrl'=>$identity['profileUrl'],'handle'=>'@'.$identity['handle'],'probeLabels'=>$probeLabels,'probe'=>'public_multi_probe'],
        ],'evidence'=>['positive'=>$probeLabels,'blocked'=>$blocked]];
    }
    if($offline)return ['state'=>'offline','error'=>'instagram_explicit_offline','confidence'=>96,'responseMs'=>$maxMs,'evidence'=>['blocked'=>$blocked]];
    return ['state'=>'unknown','error'=>$blocked>0?'instagram_blocked_or_challenged':'instagram_no_public_live_signal','confidence'=>0,'responseMs'=>$maxMs,'evidence'=>['blocked'=>$blocked,'errors'=>$errors]];
}

function p50_live_v4_parse_facebook(array $source,array $responses): array {
    $identity=p50_live_v4_identity('Facebook',(string)$source['url']);$errors=[];$maxMs=0;$ok=0;$blocked=0;$positive=[];$activeWithoutVideo=[];$endedLabels=[];$videoVotes=[];$bodies=[];$structuredLabels=[];
    foreach($responses as $label=>$r){
        $maxMs=max($maxMs,(int)($r['timeMs']??0));
        if(empty($r['ok'])){$errors[]=$label.':http_'.($r['status']??0);continue;}
        $ok++;$body=p50_live_v4_unescape((string)($r['body']??''));$probe=$body."\n".(string)($r['finalUrl']??'');$bodies[$label]=$body;
        if($body===''||p50_live_v4_block_page($probe)){$blocked++;continue;}
        $structuredActive=(bool)preg_match('/"(?:is_live_streaming|isLiveStreaming|is_live|isLive)"\s*:\s*true|"broadcast_status"\s*:\s*"(?:LIVE|ACTIVE)"/i',$probe);
        $textActive=(bool)preg_match('/\b(?:est\s+en\s+direct|en\s+direct\s+maintenant|is\s+live(?:\s+now)?|currently\s+live|live\s+now|diffusion\s+en\s+direct)\b/iu',$probe);
        $ended=(bool)preg_match('/"(?:is_live_streaming|isLiveStreaming|is_live|isLive)"\s*:\s*false|"broadcast_status"\s*:\s*"(?:VOD|ENDED|FINISHED)"|live video has ended|\bwas live\b|\bétait en direct\b|\bdirect (?:est )?termin(?:é|e)\b/iu',$probe);
        $ids=[];
        foreach([
            '#facebook\.com/(?:watch/\?v=|[^"\'<>\s]+/videos/)([1-9]\d{5,})#i',
            '#/(?:videos|live_videos)/([1-9]\d{5,})#i',
            '/"(?:video_id|videoId|broadcast_id|broadcastId)"\s*:\s*"?([1-9]\d{5,})"?/i',
        ] as $pattern)if(preg_match_all($pattern,$probe,$matches))foreach($matches[1] as $id)$ids[(string)$id]=true;
        if($ended&&!$structuredActive){$endedLabels[]=$label;continue;}
        $active=$structuredActive||$textActive;
        if(!$active)continue;
        if($structuredActive)$structuredLabels[]=$label;
        if(!$ids){$activeWithoutVideo[]=$label;continue;}
        $positive[$label]=array_keys($ids);
        foreach(array_keys($ids) as $id)$videoVotes[$id]=($videoVotes[$id]??0)+1;
    }
    if($ok===0)return ['state'=>'unknown','error'=>implode(';',$errors),'confidence'=>0,'responseMs'=>$maxMs];
    $bestId='';$votes=0;if($videoVotes){arsort($videoVotes);$bestId=(string)array_key_first($videoVotes);$votes=(int)($videoVotes[$bestId]??0);}
    $hasStructuredVideo=false;foreach($structuredLabels as $label)if(!empty($positive[$label])){$hasStructuredVideo=true;break;}
    if($bestId!==''&&$positive){
        $confidence=$hasStructuredVideo?96:($votes>=2?91:86);$bestLabel='';
        foreach(array_keys($positive) as $label)if(in_array($bestId,$positive[$label],true)){$bestLabel=$label;break;}
        if($bestLabel==='')$bestLabel=(string)array_key_first($positive);
        $bestBody=(string)($bodies[$bestLabel]??'');$meta=p50_page_metadata($bestBody,(string)($responses[$bestLabel]['finalUrl']??$identity['liveUrl']));
        $title=trim((string)($meta['title']??''));if($title===''||preg_match('/^(Facebook|Watch Facebook)$/iu',$title))$title=trim((string)($source['public_name']??'Facebook')).' est en direct';
        $url='https://www.facebook.com/watch/?v='.rawurlencode($bestId);
        return ['state'=>'live','confidence'=>$confidence,'responseMs'=>$maxMs,'live'=>['profileId'=>(string)$source['profile_id'],'platform'=>'Facebook','title'=>$title,'url'=>$url,'thumbnail'=>(string)($meta['image']??''),'confidence'=>$confidence,'startedAt'=>null,'viewers'=>p50_live_v4_viewers(implode("\n",$bodies)),'metadata'=>['profileUrl'=>$identity['profileUrl'],'videoId'=>$bestId,'probeLabels'=>array_keys($positive),'videoVotes'=>$votes,'structuredLabels'=>$structuredLabels,'probe'=>'public_multi_probe']],'evidence'=>['positive'=>array_keys($positive),'ended'=>$endedLabels,'blocked'=>$blocked,'videoVotes'=>$videoVotes]];
    }
    if($activeWithoutVideo)return ['state'=>'probable','error'=>'facebook_active_without_specific_video','confidence'=>74,'responseMs'=>$maxMs,'live'=>['profileId'=>(string)$source['profile_id'],'platform'=>'Facebook','title'=>trim((string)($source['public_name']??'Facebook')).' semble être en direct','url'=>$identity['liveUrl'],'thumbnail'=>'','confidence'=>74,'startedAt'=>null,'viewers'=>p50_live_v4_viewers(implode("\n",$bodies)),'metadata'=>['profileUrl'=>$identity['profileUrl'],'classification'=>'probable','probeLabels'=>$activeWithoutVideo]],'evidence'=>['positive'=>[],'ended'=>$endedLabels,'blocked'=>$blocked]];
    if($endedLabels)return ['state'=>'offline','error'=>'facebook_explicit_offline','confidence'=>96,'responseMs'=>$maxMs,'evidence'=>['ended'=>$endedLabels,'blocked'=>$blocked]];
    return ['state'=>'unknown','error'=>$blocked>0?'facebook_blocked_or_challenged':'facebook_no_public_live_signal','confidence'=>0,'responseMs'=>$maxMs,'evidence'=>['ended'=>$endedLabels,'blocked'=>$blocked,'errors'=>$errors]];
}

function p50_live_v4_parse_source(array $source,array $responses): array {
    return match((string)$source['platform']){
        'YouTube'=>p50_live_v4_parse_youtube($source,$responses),'TikTok'=>p50_live_v4_parse_tiktok($source,$responses),
        'Instagram'=>p50_live_v4_parse_instagram($source,$responses),'Facebook'=>p50_live_v4_parse_facebook($source,$responses),
        default=>['state'=>'unknown','error'=>'unsupported_platform','confidence'=>0],
    };
}

function p50_live_v4_scan_batch(array $sources): array {
    $jobs=[];$groups=[];
    foreach($sources as $index=>$source)foreach(p50_live_v4_probe_requests($source) as $label=>$job){$jobId=$index.'|'.$label;$jobs[$jobId]=$job;$groups[$index][$label]=$jobId;}
    $raw=p50_live_v4_parallel_fetch($jobs,7);$results=[];
    foreach($sources as $index=>$source){
        $responses=[];foreach((array)($groups[$index]??[]) as $label=>$jobId)$responses[$label]=$raw[$jobId]??[];
        $parsed=p50_live_v4_parse_source($source,$responses);$parsed['source']=$source;
        $parsed['probes']=array_map(static fn($r)=>['ok'=>(bool)($r['ok']??false),'status'=>(int)($r['status']??0),'timeMs'=>(int)($r['timeMs']??0),'error'=>(string)($r['error']??'')],$responses);$results[]=$parsed;
    }
    return $results;
}
