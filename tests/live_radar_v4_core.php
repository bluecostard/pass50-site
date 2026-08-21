<?php
declare(strict_types=1);

if(!function_exists('p50_page_metadata')){
    function p50_page_metadata(string $html,string $url): array {
        $title='';$image='';$canonical=$url;
        if(preg_match('/<title>(.*?)<\/title>/is',$html,$m))$title=html_entity_decode(strip_tags($m[1]));
        if(preg_match('/property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i',$html,$m))$image=$m[1];
        if(preg_match('/rel=["\']canonical["\'][^>]+href=["\']([^"\']+)/i',$html,$m))$canonical=$m[1];
        return ['title'=>$title,'image'=>$image,'canonical'=>$canonical];
    }
}
require dirname(__DIR__).'/api/live-radar-v4-core.php';

function must(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
function response(string $body,int $status=200,string $url='https://example.test'): array {return ['ok'=>$status>=200&&$status<400,'status'=>$status,'body'=>$body,'finalUrl'=>$url,'error'=>'','timeMs'=>12];}
function room_id_for(int $timestamp,int $suffix=123456): string {return (string)(($timestamp*4294967296)+$suffix);}

must(defined('P50_LIVE_V4_LOGIC_REVISION'),'Le moteur LIVE doit exposer une révision opérationnelle.');
must(P50_LIVE_V4_LOGIC_REVISION==='LIVE-STRICT-PUBLISH-2026-08-11-1','La révision Strict Publish doit être active.');
must(P50_LIVE_V4_TRUST_REVISION==='LIVE-STRICT-PUBLISH-2026-08-11-1','Le module Trust Gate Strict Publish doit être chargé.');
must(P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS===3600,'La fenêtre TikTok candidat est d’une heure.');
must(p50_live_v4_public_max_age('TikTok')===1800,'TikTok public max age = 30 min.');
$utcNow=gmdate('Y-m-d H:i:s');
must(p50_live_v4_parse_utc($utcNow)!==null&&abs(time()-(int)p50_live_v4_parse_utc($utcNow))<=2,'Les datetimes MySQL UTC doivent être lues en UTC.');
must(p50_live_v4_is_publicly_fresh(['status'=>'live','platform'=>'TikTok','source'=>'automatic','last_state'=>'live','last_seen_at'=>$utcNow]),'Un LIVE confirmé à l’instant doit rester public.');

$source=['profile_id'=>'coach-test','public_name'=>'Coach Test','platform'=>'TikTok','url'=>'https://www.tiktok.com/@coachtest'];
$api=p50_live_v4_parse_tiktok($source,['api'=>response('{"status":2,"room_id":"741234567890","uniqueId":"coachtest"}')]);
must($api['state']==='live','Une API TikTok structurée, active et rattachée au bon compte doit publier le LIVE.');
must(($api['live']['metadata']['roomId']??'')==='741234567890','RoomId TikTok conservé pour confirmation.');
must(($api['live']['metadata']['strictApiLabels'][0]??'')==='api','La preuve API stricte doit être conservée.');
must(p50_live_v4_is_publishable_proof($api['live']),'Une preuve TikTok stricte doit être publiable.');

$freshRoom=room_id_for(time()-300);
// Régression : streamData avec \/ imbriqués cassait json_decode après unescape (cas Dez Cocrane).
$nestedEscaped='{"data":{"user":{"uniqueId":"coachtest","roomId":"'.$freshRoom.'","status":2},"liveRoom":{"status":2,"startTime":'.(time()-120).',"streamData":"{\\\"pull\\\":\\\"https:\\\\/\\\\/example.com\\\\/live\\\"}"}},"statusCode":0}';
$apiNested=p50_live_v4_parse_tiktok($source,['api'=>response($nestedEscaped)]);
must($apiNested['state']==='live','Un JSON API TikTok avec streamData échappé doit rester décodable et confirmer le LIVE.');
must(($apiNested['live']['metadata']['strictApiLabels'][0]??'')==='api','La preuve stricte doit survivre aux échappements streamData.');

$apiFreshStatus=p50_live_v4_parse_tiktok($source,['api'=>response('{"status":2,"room_id":"'.$freshRoom.'"}')]);
must($apiFreshStatus['state']==='probable','Sans propriétaire, une salle API fraîche reste probable (Strict Publish).');

$apiFreshStructure=p50_live_v4_parse_tiktok($source,['api'=>response('{"LiveRoom":{"id":"'.$freshRoom.'"},"webcastRoomId":"'.$freshRoom.'"}')]);
must($apiFreshStructure['state']==='probable','Une structure LiveRoom seule ne publie plus.');

$staleRoom=room_id_for(time()-P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS-3600);
$apiStale=p50_live_v4_parse_tiktok($source,['api'=>response('{"LiveRoom":{"id":"'.$staleRoom.'"},"webcastRoomId":"'.$staleRoom.'"}')]);
must($apiStale['state']!=='live','Une ancienne structure LiveRoom sans identité propriétaire ne doit pas redevenir un faux direct.');

$html='<!doctype html><title>Coach Test LIVE | TikTok</title><script>{"LiveRoom":{"id":"741234567891"},"isLive":true}</script>';
$multi=p50_live_v4_parse_tiktok($source,['live'=>response($html,200,'https://www.tiktok.com/@coachtest/live'),'embed'=>response($html,200,'https://www.tiktok.com/embed/live/@coachtest')]);
must($multi['state']==='probable','Deux pages HTML sur une salle non datable restent à confirmer.');

$freshHtml='<!doctype html><title>Coach Test LIVE | TikTok</title><script>{"LiveRoom":{"id":"'.$freshRoom.'"},"isLive":true,"roomId":"'.$freshRoom.'"}</script>';
$multiFresh=p50_live_v4_parse_tiktok($source,['live'=>response($freshHtml,200,'https://www.tiktok.com/@coachtest/live'),'embed'=>response($freshHtml,200,'https://www.tiktok.com/embed/live/@coachtest')]);
must($multiFresh['state']==='probable','Deux pages HTML, même fraîches, ne publient plus sans API propriétaire.');

$cross=p50_live_v4_parse_tiktok($source,[
    'api'=>response('{"status":2,"room_id":"741234567891","uniqueId":"coachtest"}'),
    'live'=>response($html,200,'https://www.tiktok.com/@coachtest/live'),
]);
must($cross['state']==='live','Une API stricte + HTML confirme toujours le direct.');
must(($cross['live']['metadata']['proofFamilies']['api'][0]??'')==='api','La famille API est conservée.');
must(($cross['live']['metadata']['proofFamilies']['html'][0]??'')==='live','La famille HTML est conservée.');

$crossWeak=p50_live_v4_parse_tiktok($source,[
    'api'=>response('{"LiveRoom":{"id":"'.$freshRoom.'"},"webcastRoomId":"'.$freshRoom.'"}'),
    'live'=>response($freshHtml,200,'https://www.tiktok.com/@coachtest/live'),
]);
must($crossWeak['state']==='probable','Un croisement API faible + HTML reste probable.');

$single=p50_live_v4_parse_tiktok($source,['live'=>response($html,200,'https://www.tiktok.com/@coachtest/live')]);
must($single['state']==='probable','Une seule preuve HTML positive doit rester à confirmer.');

$offline=p50_live_v4_parse_tiktok($source,['api'=>response('{"liveStatus":4,"isLive":false,"uniqueId":"coachtest"}')]);
must($offline['state']==='offline','Une preuve API explicite de fin doit être hors ligne.');

$endedHtml='<!doctype html><div>Le LIVE est terminé</div><script>{"LiveRoom":{"id":"741234567891"},"isLive":true,"roomId":"741234567891"}</script>';
$endedFrench=p50_live_v4_parse_tiktok($source,['live'=>response($endedHtml,200,'https://www.tiktok.com/@coachtest/live'),'embed'=>response($endedHtml,200,'https://www.tiktok.com/embed/live/@coachtest')]);
must($endedFrench['state']==='offline','« Le LIVE est terminé » doit gagner sur des signaux HTML internes contradictoires.');

$freshApiWithEndedPage=p50_live_v4_parse_tiktok($source,[
    'api'=>response('{"LiveRoom":{"id":"'.$freshRoom.'"},"webcastRoomId":"'.$freshRoom.'"}'),
    'live'=>response($endedHtml,200,'https://www.tiktok.com/@coachtest/live'),
]);
must($freshApiWithEndedPage['state']==='offline','Sans preuve API stricte, une page « LIVE terminé » retire le direct (Trust Gate).');

$strictBeatsEndedPage=p50_live_v4_parse_tiktok($source,[
    'api'=>response('{"status":2,"room_id":"'.$freshRoom.'","uniqueId":"coachtest"}'),
    'live'=>response($endedHtml,200,'https://www.tiktok.com/@coachtest/live'),
]);
must($strictBeatsEndedPage['state']==='live','Une API stricte propriétaire gagne sur une ancienne trace HTML de fin.');

$blocked=p50_live_v4_parse_tiktok($source,['live'=>response('<html>Verify to continue - captcha</html>')]);
must($blocked['state']==='unknown','Un challenge anti-bot ne doit pas être interprété comme une fin de direct.');

$eventA=['profileId'=>'coach-test','platform'=>'TikTok','url'=>'https://www.tiktok.com/@coachtest/live','metadata'=>['roomId'=>$freshRoom]];
$eventB=['profileId'=>'coach-test','platform'=>'TikTok','url'=>'https://www.tiktok.com/@coachtest/live','metadata'=>['roomId'=>room_id_for(time()-120,654321)]];
must(p50_live_v4_stream_key($eventA)!==p50_live_v4_stream_key($eventB),'Deux salles TikTok du même compte doivent avoir des clés distinctes.');
must(p50_live_v4_event_identity($eventA)===$freshRoom,'L’identité événementielle TikTok doit provenir de roomId.');

$ytSource=['profile_id'=>'coach-hamond','public_name'=>'Coach Hamond Chic','platform'=>'YouTube','url'=>'https://www.youtube.com/@coachhamondchic'];
$ytLive=p50_live_v4_parse_youtube($ytSource,['live'=>response('<title>Direct du jour - YouTube</title><link rel="canonical" href="https://www.youtube.com/watch?v=abcDEF123"><script>{"isLiveNow":true,"startTimestamp":"2026-07-29T00:10:00Z","videoId":"abcDEF123"}</script>',200,'https://www.youtube.com/watch?v=abcDEF123')]);
must($ytLive['state']==='live','YouTube isLiveNow=true doit être LIVE.');
must(($ytLive['live']['metadata']['videoId']??'')==='abcDEF123','ID vidéo YouTube conservé.');
must(($ytLive['live']['metadata']['liveSignal']??'')==='isLiveNow','Le signal YouTube publiable doit être isLiveNow.');
must(p50_live_v4_is_publishable_proof($ytLive['live']),'Une preuve YouTube isLiveNow doit être publiable.');

$ytVod=p50_live_v4_parse_youtube($ytSource,['live'=>response('<title>Ancien live - YouTube</title><link rel="canonical" href="https://www.youtube.com/watch?v=abcDEF123"><script>{"isLiveNow":false,"isLiveContent":true,"playabilityStatus":{"status":"OK"},"videoId":"abcDEF123"}</script>',200,'https://www.youtube.com/watch?v=abcDEF123')]);
must($ytVod['state']==='replay','isLiveContent+OK sans isLiveNow doit être traité comme VOD, pas LIVE.');

$ytReplay=p50_live_v4_parse_youtube($ytSource,['live'=>response('<title>Replay du jour - YouTube</title><link rel="canonical" href="https://www.youtube.com/watch?v=abcDEF123"><script>{"isLiveNow":false,"isLiveContent":true,"endTimestamp":"2026-07-29T01:00:00Z","videoId":"abcDEF123"}</script>',200,'https://www.youtube.com/watch?v=abcDEF123')]);
must(p50_live_v4_known_false_positive(['platform'=>'YouTube','url'=>'https://www.youtube.com/watch?v=TOa6dTjz7V0']),'La vidéo Kévine Obin signalée doit être reconnue comme faux positif.');
$ytFalsePositive=p50_live_v4_parse_youtube($ytSource,['live'=>response('<title>Je suis désolé. - YouTube</title><link rel="canonical" href="https://www.youtube.com/watch?v=TOa6dTjz7V0"><script>{"isLiveNow":true,"videoId":"TOa6dTjz7V0"}</script>',200,'https://www.youtube.com/watch?v=TOa6dTjz7V0')]);
must(($ytFalsePositive['state']??'')==='replay'&&($ytFalsePositive['error']??'')==='known_false_positive','Le faux live précis doit être retiré même si YouTube renvoie isLiveNow.');
must($ytReplay['state']==='replay','Une fin YouTube explicite doit devenir replay et non LIVE.');

$instagram=p50_live_v4_parse_instagram(['profile_id'=>'ig','public_name'=>'IG','platform'=>'Instagram','url'=>'https://www.instagram.com/test/'],['profile'=>response('{"is_live_broadcast":true}')]);
must($instagram['state']==='live','Signal Instagram actif explicite.');

$facebook=p50_live_v4_parse_facebook(['profile_id'=>'fb','public_name'=>'FB','platform'=>'Facebook','url'=>'https://www.facebook.com/test'],['live'=>response('{"is_live_streaming":true,"video_id":"123456789"} https://www.facebook.com/test/videos/123456789')]);
must($facebook['state']==='live','Signal Facebook actif et vidéo spécifique.');

$verifiedOffline=['profile_id'=>'apoutchou','platform'=>'TikTok','verification_status'=>'owner_verified','last_state'=>'offline','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-7200),'last_live_at'=>gmdate('Y-m-d H:i:s',time()-86400)];
$genericOffline=['profile_id'=>'other','platform'=>'TikTok','verification_status'=>'verified','last_state'=>'offline','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-120)];
must(p50_live_v4_needs_tiktok_rescan($verifiedOffline),'Un TikTok vérifié offline depuis 2 h doit être rescané.');
must(!p50_live_v4_needs_tiktok_rescan($genericOffline),'Un TikTok vérifié contrôlé il y a 2 min ne doit pas saturer le rescan.');
must(p50_live_v4_discovery_rank($verifiedOffline)<p50_live_v4_discovery_rank($genericOffline),'Le TikTok vérifié stale doit passer avant un offline récent non prioritaire.');

$p0Stale=['profile_id'=>'general-camille-makosso','platform'=>'TikTok','verification_status'=>'owner_verified','last_state'=>'offline','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_is_p0_tiktok($p0Stale),'Général Makosso doit être en watchlist P0 TikTok.');
must(p50_live_v4_needs_tiktok_rescan($p0Stale),'Un P0 TikTok offline depuis 130 s doit être rescané.');
$p0Fresh=['profile_id'=>'general-camille-makosso','platform'=>'TikTok','verification_status'=>'owner_verified','last_state'=>'offline','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-60)];
must(!p50_live_v4_needs_tiktok_rescan($p0Fresh),'Un P0 TikTok contrôlé il y a 60 s ne doit pas être resondé.');

$noLimitP0=['profile_id'=>'census-no-limit','platform'=>'TikTok','verification_status'=>'ok','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_is_p0_tiktok($noLimitP0),'No Limit doit être en watchlist P0 TikTok même sans statut verified.');
must(p50_live_v4_needs_tiktok_rescan($noLimitP0),'Un P0 No Limit unknown depuis 130 s doit être rescané.');
foreach(['census-amour-ruth-poopy','census-jordan-evraa','dbz','maabio','census-el-profesor','census-sarara-messan','louissette','p_1785175190809','aya-robert','hamondchic','dez-cocrane225','census-roseline-layo','census-rach-makosso','census-jp-nda','census-cahie-kunta','census-lise-akrassi','census-lexes','census-ange-morel','census-laguepe','census-rosemark-marcel','census-jiaan-wu','census-samuella-kouassi','oustaz-diane'] as $liveId){
    $p0=['profile_id'=>$liveId,'platform'=>'TikTok','verification_status'=>'ok','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
    must(p50_live_v4_is_p0_tiktok($p0),$liveId.' doit être en watchlist P0.');
}
$observateurYt=['profile_id'=>'census-observateur-ebene','platform'=>'YouTube','verification_status'=>'ok','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_is_p0_youtube($observateurYt),'Observateur Ébène YouTube doit être en watchlist P0.');
must(p50_live_v4_is_p0_source($observateurYt),'Observateur Ébène YouTube doit être une source P0.');
must(p50_live_v4_needs_p0_rescan($observateurYt),'Un P0 YouTube unknown depuis 130 s doit être rescané.');
$rosemarkYt=['profile_id'=>'census-rosemark-marcel','platform'=>'YouTube','verification_status'=>'ok','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_is_p0_youtube($rosemarkYt),'Rosemark Marcel YouTube doit être en watchlist P0.');
must(p50_live_v4_is_p0_source($rosemarkYt),'Rosemark Marcel YouTube doit être une source P0.');
must(p50_live_v4_needs_p0_rescan($rosemarkYt),'Un P0 YouTube Rosemark Marcel unknown depuis 130 s doit être rescané.');

$unknownTikTok=['profile_id'=>'census-nadiani','platform'=>'TikTok','verification_status'=>'ok','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_is_unknown_tiktok($unknownTikTok),'Un TikTok unknown doit être reconnu comme tel.');
must(!p50_live_v4_needs_tiktok_rescan($unknownTikTok),'Un unknown hors P0 ne doit pas saturer le rescan 2 min.');
$metaUnknown=['profile_id'=>'ig-unknown','platform'=>'Instagram','verification_status'=>'verified','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_discovery_rank($unknownTikTok)<p50_live_v4_discovery_rank($metaUnknown),'Un TikTok unknown passe avant un Instagram unknown.');

$noLimitSource=['profile_id'=>'census-no-limit','public_name'=>'No Limit','platform'=>'TikTok','url'=>'https://www.tiktok.com/@nolimit_vousdv'];
$noLimitApi=p50_live_v4_parse_tiktok($noLimitSource,['api'=>response('{"data":{"user":{"uniqueId":"nolimit_vousdv","roomId":"7675157318859639573","status":2},"liveRoom":{"status":2,"startTime":1787011835}},"statusCode":0}')]);
must($noLimitApi['state']==='live','L’API TikTok No Limit status=2 + uniqueId doit publier le LIVE.');

$jordanSource=['profile_id'=>'census-jordan-evraa','public_name'=>'Jordan Evraa','platform'=>'TikTok','url'=>'https://www.tiktok.com/@realjordanevraa'];
$webcastLive=p50_live_v4_parse_tiktok($jordanSource,['api_webcast'=>response('{"data":{"status":2,"id":7675133122324843295,"id_str":"7675133122324843295","title":"Goumin tv","user_count":147,"owner":{"display_id":"realjordanevraa","nickname":"JORDAN EVRAA"}},"status_code":0}')]);
must($webcastLive['state']==='live','L’API webcast status=2 + display_id doit publier le LIVE même sans www.tiktok.com/api-live.');
must(($webcastLive['live']['metadata']['roomId']??'')==='7675133122324843295','Le roomId webcast doit être lu depuis id_str.');
must(($webcastLive['live']['metadata']['strictApiLabels'][0]??'')==='api_webcast','La preuve stricte peut venir de api_webcast.');
must(($webcastLive['live']['title']??'')==='Goumin tv est en direct'||str_contains((string)($webcastLive['live']['title']??''),'Goumin tv'),'Le titre webcast doit primer sur l’embed.');

$hamondSource=['profile_id'=>'hamondchic','public_name'=>'Coach Hamond Chic','platform'=>'TikTok','url'=>'https://www.tiktok.com/@coachhamond'];
$hamondLive=p50_live_v4_parse_tiktok($hamondSource,['api_webcast'=>response('{"data":{"status":2,"id":7675380840767048470,"id_str":"7675380840767048470","title":"Allô yougoss","user_count":13346,"owner":{"display_id":"coachhamond","nickname":"coachhamondchic"}},"status_code":0}'),'embed'=>response('<!doctype html><title>TikTok Embed LIVE</title>',200,'https://www.tiktok.com/embed/live/@coachhamond')]);
must($hamondLive['state']==='live','Coach Hamond Chic webcast status=2 doit publier le LIVE.');
must(str_contains((string)($hamondLive['live']['title']??''),'Allô yougoss'),'Le titre Allô yougoss doit primer sur TikTok Embed LIVE.');

$hamondApiDown=p50_live_v4_parse_tiktok($hamondSource,[
    'api'=>['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>'https://www.tiktok.com/api-live/user/room/','error'=>'timeout','timeMs'=>4000],
    'api_webcast'=>['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>'https://webcast.tiktok.com/webcast/room/info_by_user/','error'=>'timeout','timeMs'=>4000],
    'embed'=>response('<!doctype html><title>TikTok</title><p>This live has ended</p>',200,'https://www.tiktok.com/embed/live/@coachhamond'),
]);
must($hamondApiDown['state']==='unknown','Un embed « ended » sans API ne doit pas clôturer Coach Hamond.');

$dezSource=['profile_id'=>'dez-cocrane225','public_name'=>'Dez Cocrane 225','platform'=>'TikTok','url'=>'https://www.tiktok.com/@dezcocrane.225'];
$dezLive=p50_live_v4_parse_tiktok($dezSource,['api_webcast'=>response('{"data":{"status":2,"id":7675422496225168161,"id_str":"7675422496225168161","title":"","user_count":504,"owner":{"display_id":"dezcocrane.225","nickname":"Dez Cocrane 225"}},"status_code":0}')]);
must($dezLive['state']==='live','Dez Cocrane 225 webcast status=2 doit publier le LIVE.');
must(($dezLive['live']['metadata']['roomId']??'')==='7675422496225168161','Le roomId Dez Cocrane doit être conservé.');

$roselineSource=['profile_id'=>'census-roseline-layo','public_name'=>'Roseline Layo','platform'=>'TikTok','url'=>'https://www.tiktok.com/@roselinelayoofficiel'];
$roselineLive=p50_live_v4_parse_tiktok($roselineSource,['api_webcast'=>response('{"data":{"status":2,"id":7675480011223344556,"id_str":"7675480011223344556","title":"En direct","user_count":3200,"owner":{"display_id":"roselinelayoofficiel","nickname":"Roseline Layo"}},"status_code":0}')]);
must($roselineLive['state']==='live','Roseline Layo webcast status=2 doit publier le LIVE.');
must(($roselineLive['live']['metadata']['roomId']??'')==='7675480011223344556','Le roomId Roseline Layo doit être conservé.');

$rachSource=['profile_id'=>'census-rach-makosso','public_name'=>'Rach Makosso','platform'=>'TikTok','url'=>'https://www.tiktok.com/@rach_makosso1'];
$rachLive=p50_live_v4_parse_tiktok($rachSource,['api_webcast'=>response('{"data":{"status":2,"id":7675480011223344666,"id_str":"7675480011223344666","title":"En direct","user_count":1800,"owner":{"display_id":"rach_makosso1","nickname":"Rach Makosso"}},"status_code":0}')]);
must($rachLive['state']==='live','Rach Makosso webcast status=2 doit publier le LIVE.');
must(($rachLive['live']['metadata']['roomId']??'')==='7675480011223344666','Le roomId Rach Makosso doit être conservé.');

$angeMorelSource=['profile_id'=>'census-ange-morel','public_name'=>'Ange-Morel Your Eyes','platform'=>'TikTok','url'=>'https://www.tiktok.com/@angemorel4'];
$angeMorelLive=p50_live_v4_parse_tiktok($angeMorelSource,['api_webcast'=>response('{"data":{"status":2,"id":7675480011223344777,"id_str":"7675480011223344777","title":"En direct","user_count":2100,"owner":{"display_id":"angemorel4","nickname":"Ange-Morel Your Eyes"}},"status_code":0}')]);
must($angeMorelLive['state']==='live','Ange-Morel Your Eyes webcast status=2 doit publier le LIVE.');
must(($angeMorelLive['live']['metadata']['roomId']??'')==='7675480011223344777','Le roomId Ange-Morel Your Eyes doit être conservé.');

$laguepeSource=['profile_id'=>'census-laguepe','public_name'=>'Laguepe','platform'=>'TikTok','url'=>'https://www.tiktok.com/@laguepe03'];
$laguepeLive=p50_live_v4_parse_tiktok($laguepeSource,['api_webcast'=>response('{"data":{"status":2,"id":7675480011223344888,"id_str":"7675480011223344888","title":"En direct","user_count":890,"owner":{"display_id":"laguepe03","nickname":"Laguepe"}},"status_code":0}')]);
must($laguepeLive['state']==='live','Laguepe webcast status=2 doit publier le LIVE.');
must(($laguepeLive['live']['metadata']['roomId']??'')==='7675480011223344888','Le roomId Laguepe doit être conservé.');

$rosemarkSource=['profile_id'=>'census-rosemark-marcel','public_name'=>'Rosemark Marcel','platform'=>'TikTok','url'=>'https://www.tiktok.com/@rosemarkmarcel'];
$rosemarkLive=p50_live_v4_parse_tiktok($rosemarkSource,['api_webcast'=>response('{"data":{"status":2,"id":7675480011223344999,"id_str":"7675480011223344999","title":"En direct","user_count":1200,"owner":{"display_id":"rosemarkmarcel","nickname":"Rosemark Marcel"}},"status_code":0}')]);
must($rosemarkLive['state']==='live','Rosemark Marcel webcast status=2 doit publier le LIVE.');
must(($rosemarkLive['live']['metadata']['roomId']??'')==='7675480011223344999','Le roomId Rosemark Marcel doit être conservé.');

$jiaanYt=['profile_id'=>'census-jiaan-wu','platform'=>'YouTube','verification_status'=>'ok','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_is_p0_youtube($jiaanYt),'Jiaan Wu YouTube doit être en watchlist P0.');
must(p50_live_v4_is_p0_source($jiaanYt),'Jiaan Wu YouTube doit être une source P0.');
must(p50_live_v4_needs_p0_rescan($jiaanYt),'Un P0 YouTube Jiaan Wu unknown depuis 130 s doit être rescané.');
$oustazYt=['profile_id'=>'oustaz-diane','platform'=>'YouTube','verification_status'=>'ok','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_is_p0_youtube($oustazYt),'Oustaz Diané YouTube doit être en watchlist P0.');
must(p50_live_v4_is_p0_source($oustazYt),'Oustaz Diané YouTube doit être une source P0.');
must(p50_live_v4_needs_p0_rescan($oustazYt),'Un P0 YouTube Oustaz Diané unknown depuis 130 s doit être rescané.');

$jiaanSource=['profile_id'=>'census-jiaan-wu','public_name'=>'Jiaan Wu','platform'=>'TikTok','url'=>'https://www.tiktok.com/@jiaaan.wu'];
$jiaanLive=p50_live_v4_parse_tiktok($jiaanSource,['api_webcast'=>response('{"data":{"status":2,"id":7675480011223345111,"id_str":"7675480011223345111","title":"En direct","user_count":1500,"owner":{"display_id":"jiaaan.wu","nickname":"Jiaan Wu"}},"status_code":0}')]);
must($jiaanLive['state']==='live','Jiaan Wu webcast status=2 doit publier le LIVE.');
must(($jiaanLive['live']['metadata']['roomId']??'')==='7675480011223345111','Le roomId Jiaan Wu doit être conservé.');

$samuellaP0=['profile_id'=>'census-samuella-kouassi','platform'=>'TikTok','verification_status'=>'ok','last_state'=>'unknown','last_checked_at'=>gmdate('Y-m-d H:i:s',time()-130)];
must(p50_live_v4_is_p0_tiktok($samuellaP0),'Samuella Kouassi TikTok doit être en watchlist P0.');
must(p50_live_v4_needs_tiktok_rescan($samuellaP0),'Un P0 TikTok Samuella Kouassi unknown depuis 130 s doit être rescané.');

$samuellaSource=['profile_id'=>'census-samuella-kouassi','public_name'=>'Samuella Kouassi','platform'=>'TikTok','url'=>'https://www.tiktok.com/@samuellakouassiofficiel'];
$samuellaLive=p50_live_v4_parse_tiktok($samuellaSource,['api_webcast'=>response('{"data":{"status":2,"id":7675480011223345222,"id_str":"7675480011223345222","title":"En direct","user_count":2400,"owner":{"display_id":"samuellakouassiofficiel","nickname":"Samuella Kouassi"}},"status_code":0}')]);
must($samuellaLive['state']==='live','Samuella Kouassi webcast status=2 doit publier le LIVE.');
must(($samuellaLive['live']['metadata']['roomId']??'')==='7675480011223345222','Le roomId Samuella Kouassi doit être conservé.');

$cahieSource=['profile_id'=>'census-cahie-kunta','public_name'=>'Cahié kunta','platform'=>'TikTok','url'=>'https://www.tiktok.com/@cahiekunta'];
$cahieLive=p50_live_v4_parse_tiktok($cahieSource,['api_webcast'=>response('{"data":{"status":2,"id":7676614414696368916,"id_str":"7676614414696368916","title":"QUEL EST TON PROBLEME","user_count":339,"owner":{"display_id":"cahiekunta","nickname":"Cahié kunta"}},"status_code":0}')]);
must($cahieLive['state']==='live','Cahié kunta webcast status=2 doit publier le LIVE.');
must(($cahieLive['live']['metadata']['roomId']??'')==='7676614414696368916','Le roomId Cahié kunta doit être conservé.');
must(!p50_live_v4_should_end_from_probe('live',['state'=>'offline','error'=>'tiktok_no_live_signal']),'Un HTML IONOS sans JSON ne clôture pas un LIVE encore confirmé.');
must(!p50_live_v4_should_end_from_probe('never_checked',['state'=>'offline','error'=>'tiktok_no_live_signal']),'IONOS sans API TikTok ne clôture jamais un compte, même jamais sondé.');
must(!p50_live_v4_should_end_from_probe('live',['state'=>'offline','error'=>'tiktok_api_failed']),'Un 403 IONOS ne clôture pas un LIVE confirmé.');
must(p50_live_v4_should_end_from_probe('live',['state'=>'offline','error'=>'tiktok_live_ended']),'Une fin API/HTML explicite clôture toujours le direct.');

must(p50_live_v4_canonical_profile_id('p_ghost_sa','TikTok','samuellakouassiofficiel')==='census-samuella-kouassi','Le handle @samuellakouassiofficiel doit fusionner sur la fiche officielle.');
must(p50_live_v4_canonical_profile_id('other','TikTok','jiaaan.wu')==='other','Un handle hors table canonique reste inchangé.');
$collapsed=p50_live_v4_collapse_identity_sources([
    ['profile_id'=>'p_ghost_sa','public_name'=>'','platform'=>'TikTok','url'=>'https://www.tiktok.com/@samuellakouassiofficiel','verification_status'=>'ok','confidence'=>90],
    ['profile_id'=>'census-samuella-kouassi','public_name'=>'Samuella Kouassi','platform'=>'TikTok','url'=>'https://www.tiktok.com/@samuellakouassiofficiel','verification_status'=>'manual_verified','confidence'=>100],
]);
must(count($collapsed)===1,'Un seul sondage TikTok Samuella doit rester après fusion.');
must(($collapsed[0]['profile_id']??'')==='census-samuella-kouassi','La source fusionnée doit rester la fiche officielle Samuella.');
$dupGhost=['profileId'=>'p_ghost_sa','platform'=>'TikTok','url'=>'https://www.tiktok.com/@samuellakouassiofficiel/live','handle'=>'@samuellakouassiofficiel','title'=>'Samuella Kouassi est en direct','metadata'=>['roomId'=>'7675480011223345222','handle'=>'@samuellakouassiofficiel']];
$dupOfficial=['profileId'=>'census-samuella-kouassi','platform'=>'TikTok','url'=>'https://www.tiktok.com/@samuellakouassiofficiel/live','handle'=>'@samuellakouassiofficiel','title'=>'Samuella Kouassi est en direct','metadata'=>['roomId'=>'7675480011223345222','handle'=>'@samuellakouassiofficiel']];
$deduped=p50_live_v4_dedup([$dupGhost,$dupOfficial],[]);
must(count($deduped)===1,'Un même live TikTok Samuella ne doit publier qu’une carte.');
must(($deduped[0]['profileId']??'')==='census-samuella-kouassi','La carte publique doit rester sur Samuella Kouassi, pas Influenceur.');

$embedOnlyBlocked=p50_live_v4_parse_tiktok($jordanSource,[
    'api'=>['ok'=>false,'status'=>403,'body'=>'','finalUrl'=>'https://www.tiktok.com/api-live/user/room/','error'=>'http_403','timeMs'=>8],
    'live'=>['ok'=>false,'status'=>0,'body'=>'','finalUrl'=>'https://www.tiktok.com/@realjordanevraa/live','error'=>'blocked_or_challenged','timeMs'=>8],
    'embed'=>response('<!doctype html><title>TikTok</title><div id="app"></div>',200,'https://www.tiktok.com/embed/live/@realjordanevraa'),
]);
must($embedOnlyBlocked['state']==='unknown','Un embed sans JSON live + API 403 ne doit pas classer un direct comme hors ligne.');

$readableOffline=p50_live_v4_parse_tiktok($source,['profile'=>response('<!doctype html><title>Coach Test | TikTok</title><script>{"uniqueId":"coachtest","videoCount":12}</script>',200,'https://www.tiktok.com/@coachtest')]);
must($readableOffline['state']==='offline','Une page profil lisible sans signal live reste hors ligne.');

$apiDownProfile=p50_live_v4_parse_tiktok($source,[
    'api'=>['ok'=>false,'status'=>403,'body'=>'','finalUrl'=>'https://www.tiktok.com/api-live/user/room/','error'=>'http_403','timeMs'=>8],
    'api_webcast'=>['ok'=>false,'status'=>403,'body'=>'','finalUrl'=>'https://webcast.tiktok.com/webcast/room/info_by_user/','error'=>'http_403','timeMs'=>8],
    'profile'=>response('<!doctype html><title>Coach Test | TikTok</title><script>{"uniqueId":"coachtest","videoCount":12}</script>',200,'https://www.tiktok.com/@coachtest'),
]);
must($apiDownProfile['state']==='unknown','API TikTok injoignable + page profil lisible ne doit pas classer hors ligne.');
must(($apiDownProfile['error']??'')==='tiktok_api_failed','Le motif IONOS doit rester tiktok_api_failed.');

$requests=p50_live_v4_probe_requests($jordanSource);
must(isset($requests['api_webcast']),'Jordan Evraa doit être sondé via webcast.tiktok.com.');
must(str_contains((string)$requests['api_webcast']['url'],'webcast.tiktok.com/webcast/room/info_by_user'),'La sonde webcast doit viser info_by_user.');

$merged=p50_live_v4_merge_p0_watch(
    [['profileId'=>'census-jordan-evraa','platform'=>'TikTok','handle'=>'realjordanevraa']],
    [['profileId'=>'census-jordan-evraa','platform'=>'TikTok'],['profileId'=>'yt-coach','platform'=>'YouTube']]
);
must(count($merged)===2,'La watchlist P0 dynamique ne doit pas dupliquer un même compte.');
must($merged[1]['platform']==='YouTube','YouTube unknown vraiment en live peut entrer en P0.');
must(p50_live_v4_p0_key('Census-Jordan-Evraa','TikTok')==='census-jordan-evraa|tiktok','La clé P0 est insensible à la casse.');

echo json_encode(['ok'=>true,'cases'=>73],JSON_UNESCAPED_SLASHES).PHP_EOL;
