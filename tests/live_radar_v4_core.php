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

$source=['profile_id'=>'coach-test','public_name'=>'Coach Test','platform'=>'TikTok','url'=>'https://www.tiktok.com/@coachtest'];
$api=p50_live_v4_parse_tiktok($source,['api'=>response('{"status":2,"room_id":"741234567890","uniqueId":"coachtest"}')]);
must($api['state']==='probable','Une API TikTok isolée ne doit plus publier un LIVE.');
must(($api['live']['metadata']['roomId']??'')==='741234567890','RoomId TikTok conservé pour confirmation.');

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
must($single['state']==='probable','Une seule preuve positive doit rester à confirmer.');

$offline=p50_live_v4_parse_tiktok($source,['api'=>response('{"liveStatus":4,"isLive":false,"uniqueId":"coachtest"}')]);
must($offline['state']==='offline','Une preuve API explicite de fin doit être hors ligne.');

$endedHtml='<!doctype html><div>Le LIVE est terminé</div><script>{"LiveRoom":{"id":"741234567891"},"isLive":true,"roomId":"741234567891"}</script>';
$endedFrench=p50_live_v4_parse_tiktok($source,['live'=>response($endedHtml,200,'https://www.tiktok.com/@coachtest/live'),'embed'=>response($endedHtml,200,'https://www.tiktok.com/embed/live/@coachtest')]);
must($endedFrench['state']==='offline','« Le LIVE est terminé » doit gagner sur les anciens roomId et LiveRoom.');
must(($endedFrench['error']??'')==='tiktok_live_ended','La raison de fin TikTok doit être explicite.');

$apiLiveWithEndedPage=p50_live_v4_parse_tiktok($source,[
    'api'=>response('{"status":2,"room_id":"741234567892","uniqueId":"coachtest"}'),
    'live'=>response($endedHtml,200,'https://www.tiktok.com/@coachtest/live'),
]);
must($apiLiveWithEndedPage['state']==='offline','Une page LIVE terminée doit gagner sur un ancien statut API actif.');

$blocked=p50_live_v4_parse_tiktok($source,['live'=>response('<html>Verify to continue - captcha</html>')]);
must($blocked['state']==='unknown','Un challenge anti-bot ne doit pas être interprété comme une fin de direct.');

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

echo json_encode(['ok'=>true,'cases'=>12],JSON_UNESCAPED_SLASHES).PHP_EOL;
