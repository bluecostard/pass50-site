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
must(P50_LIVE_V4_LOGIC_REVISION==='LIVE-RADAR-CONTINUOUS-MAX-2026-08-02-1','La révision de continuité maximale doit être active.');
must(P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS===43200,'La fenêtre TikTok doit rester conservatrice à douze heures.');

$source=['profile_id'=>'coach-test','public_name'=>'Coach Test','platform'=>'TikTok','url'=>'https://www.tiktok.com/@coachtest'];
$api=p50_live_v4_parse_tiktok($source,['api'=>response('{"status":2,"room_id":"741234567890","uniqueId":"coachtest"}')]);
must($api['state']==='live','Une API TikTok structurée, active et rattachée au bon compte doit publier le LIVE.');
must(($api['live']['metadata']['roomId']??'')==='741234567890','RoomId TikTok conservé pour confirmation.');
must(($api['live']['metadata']['strictApiLabels'][0]??'')==='api','La preuve API stricte doit être conservée.');

$freshRoom=room_id_for(time()-300);
$apiFreshStatus=p50_live_v4_parse_tiktok($source,['api'=>response('{"status":2,"room_id":"'.$freshRoom.'"}')]);
must($apiFreshStatus['state']==='live','Une salle TikTok récente avec statut actif doit confirmer le direct.');
must(($apiFreshStatus['live']['metadata']['freshApiLabels'][0]??'')==='api','La preuve temporelle fraîche doit être conservée.');

$apiFreshStructure=p50_live_v4_parse_tiktok($source,['api'=>response('{"LiveRoom":{"id":"'.$freshRoom.'"},"webcastRoomId":"'.$freshRoom.'"}')]);
must($apiFreshStructure['state']==='live','Une structure LiveRoom récente doit confirmer le direct même sans champ status.');
must(($apiFreshStructure['live']['metadata']['apiLiveStructureLabels'][0]??'')==='api','La structure LiveRoom doit rester visible dans le diagnostic.');
must(($apiFreshStructure['live']['startedAt']??null)!==null,'La date encodée dans la salle TikTok doit devenir la date de début.');

$staleRoom=room_id_for(time()-P50_LIVE_V4_TIKTOK_FRESH_ROOM_SECONDS-3600);
$apiStale=p50_live_v4_parse_tiktok($source,['api'=>response('{"LiveRoom":{"id":"'.$staleRoom.'"},"webcastRoomId":"'.$staleRoom.'"}')]);
must($apiStale['state']==='probable','Une ancienne structure LiveRoom sans identité propriétaire ne doit pas redevenir un faux direct.');

$html='<!doctype html><title>Coach Test LIVE | TikTok</title><script>{"LiveRoom":{"id":"741234567891"},"isLive":true}</script>';
$multi=p50_live_v4_parse_tiktok($source,['live'=>response($html,200,'https://www.tiktok.com/@coachtest/live'),'embed'=>response($html,200,'https://www.tiktok.com/embed/live/@coachtest')]);
must($multi['state']==='probable','Deux pages HTML de la même famille restent à confirmer.');

$cross=p50_live_v4_parse_tiktok($source,[
    'api'=>response('{"status":2,"room_id":"741234567891","uniqueId":"coachtest"}'),
    'live'=>response($html,200,'https://www.tiktok.com/@coachtest/live'),
]);
must($cross['state']==='live','Une API et une page LIVE cohérentes doivent confirmer le direct.');
must(($cross['live']['metadata']['proofFamilies']['api'][0]??'')==='api','La famille API est conservée.');
must(($cross['live']['metadata']['proofFamilies']['html'][0]??'')==='live','La famille HTML est conservée.');

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
must($freshApiWithEndedPage['state']==='live','Une structure LiveRoom fraîche doit gagner sur une ancienne trace HTML de fin.');

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

$ytReplay=p50_live_v4_parse_youtube($ytSource,['live'=>response('<title>Replay du jour - YouTube</title><link rel="canonical" href="https://www.youtube.com/watch?v=abcDEF123"><script>{"isLiveNow":false,"isLiveContent":true,"endTimestamp":"2026-07-29T01:00:00Z","videoId":"abcDEF123"}</script>',200,'https://www.youtube.com/watch?v=abcDEF123')]);
must($ytReplay['state']==='replay','Une fin YouTube explicite doit devenir replay et non LIVE.');

$instagram=p50_live_v4_parse_instagram(['profile_id'=>'ig','public_name'=>'IG','platform'=>'Instagram','url'=>'https://www.instagram.com/test/'],['profile'=>response('{"is_live_broadcast":true}')]);
must($instagram['state']==='live','Signal Instagram actif explicite.');

$facebook=p50_live_v4_parse_facebook(['profile_id'=>'fb','public_name'=>'FB','platform'=>'Facebook','url'=>'https://www.facebook.com/test'],['live'=>response('{"is_live_streaming":true,"video_id":"123456789"} https://www.facebook.com/test/videos/123456789')]);
must($facebook['state']==='live','Signal Facebook actif et vidéo spécifique.');

echo json_encode(['ok'=>true,'cases'=>20],JSON_UNESCAPED_SLASHES).PHP_EOL;
