<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/metrics-collectors-core.php';

$dsn=getenv('P50_TEST_DSN');if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$youtubeCredential=hash('sha256','youtube-fixture');$xCredential=hash('sha256','x-fixture');
$config=['data_engine'=>['confidence_threshold'=>90],'metrics'=>['PASS50_YOUTUBE_API_KEY'=>$youtubeCredential,'x_bearer_token'=>$xCredential]];
function must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
must(p50_mc_youtube_content_type(['snippet'=>['isShort'=>true]])===['short','short'],'Type Short');
must(p50_mc_youtube_content_type(['snippet'=>['liveBroadcastContent'=>'live']])===['live','live'],'Type live');
must(p50_mc_youtube_content_type(['liveStreamingDetails'=>['actualStartTime'=>'2026-07-28T08:00:00Z','actualEndTime'=>'2026-07-28T09:00:00Z']])===['video','replay'],'Type rediffusion');
foreach(['p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_social_links','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190),alive TINYINT NOT NULL,score DECIMAL(6,2),rank_position INT)");
$pdo->exec("CREATE TABLE p50_social_links(profile_id VARCHAR(100),platform VARCHAR(32),normalized_url TEXT,confidence INT,status VARCHAR(24),PRIMARY KEY(profile_id,platform))");
$pdo->exec("CREATE TABLE app_state(id INT PRIMARY KEY,state_json LONGTEXT,version INT)");
$pdo->exec("INSERT INTO app_state VALUES(1,'{\"sentinel\":true}',77)");
$pdo->exec("INSERT INTO p50_profile_registry VALUES('yt','YouTube Fixture',1,42.50,7),('x','X Fixture',1,41.00,8),('yt-fallback','YouTube Fallback',1,40.00,9),('x-blocked','X Blocked',1,39.00,10)");
$pdo->exec("INSERT INTO p50_social_links VALUES('yt','YouTube','https://youtube.com/@Fixture',98,'verified'),('x','X','https://x.com/FixtureX',97,'verified'),('yt-fallback','YouTube','https://youtube.com/channel/UCfallback',95,'verified'),('x-blocked','X','https://x.com/BlockedFixture',95,'verified')");

$fetch=function(string $url,array $headers=[]): array {
    if(str_contains($url,'youtube/v3/channels'))$body=['items'=>[['id'=>'UCfixture123','snippet'=>['title'=>'Fixture'],'statistics'=>['subscriberCount'=>'0','viewCount'=>'1000','videoCount'=>'1','hiddenSubscriberCount'=>false],'contentDetails'=>['relatedPlaylists'=>['uploads'=>'UUfixture']]]]];
    elseif(str_contains($url,'playlistItems'))$body=['items'=>[['contentDetails'=>['videoId'=>'vid123']]]];
    elseif(str_contains($url,'youtube/v3/videos'))$body=['items'=>[['id'=>'vid123','snippet'=>['title'=>'Video','publishedAt'=>'2026-07-28T10:00:00Z'],'status'=>['privacyStatus'=>'public'],'statistics'=>['viewCount'=>'20','likeCount'=>'0']]]];
    elseif(str_contains($url,'users/by/username'))$body=['data'=>['id'=>'9001','username'=>'fixturex','public_metrics'=>['followers_count'=>0,'following_count'=>12,'tweet_count'=>33]]];
    elseif(str_contains($url,'/tweets?'))$body=['data'=>[['id'=>'19001','text'=>'Post fixture','created_at'=>'2026-07-28T10:05:00Z','public_metrics'=>['impression_count'=>50,'like_count'=>0,'reply_count'=>2,'retweet_count'=>3,'quote_count'=>1,'bookmark_count'=>4]]]];
    else return ['status'=>404,'body'=>'','url'=>$url,'error'=>'fixture missing'];
    return ['status'=>200,'body'=>json_encode($body),'url'=>$url,'error'=>''];
};

$yt=p50_metrics_collect_profile($pdo,'yt','YouTube',5,$fetch,'2026-07-28T12:00:00Z');
$x=p50_metrics_collect_profile($pdo,'x','X',5,$fetch,'2026-07-28T12:00:00Z');
must($yt['accountFound']&&$yt['contentsFound']===1&&$yt['capturesRecorded']===2,'Collecte YouTube');
must($x['accountFound']&&$x['contentsFound']===1&&$x['capturesRecorded']===2,'Collecte X');
must((int)p50_metrics_value($pdo,"SELECT followers FROM p50_metric_captures WHERE platform='YouTube' AND content_id IS NULL")===0,'Zéro abonnés conservé');
must(p50_metrics_value($pdo,"SELECT comments FROM p50_metric_captures WHERE platform='YouTube' AND content_id IS NOT NULL")===null,'Commentaires absents restent NULL');
must((int)p50_metrics_value($pdo,"SELECT shares FROM p50_metric_captures WHERE platform='X' AND content_id IS NOT NULL")===3,'Reposts explicites');

$again=p50_metrics_collect_profile($pdo,'yt','YouTube',5,$fetch,'2026-07-28T12:00:00Z');
must($again['runUuid']!==$yt['runUuid']&&$again['capturesRecorded']===0&&$again['duplicatesSkipped']===2,'Retry dans un autre run ignoré');
$changedFetch=function(string $url,array $headers=[]) use($fetch): array {
    $response=$fetch($url,$headers);
    if(str_contains($url,'youtube/v3/videos')){$body=json_decode($response['body'],true);$body['items'][0]['statistics']['viewCount']='21';$response['body']=json_encode($body);}
    return $response;
};
$changed=p50_metrics_collect_profile($pdo,'yt','YouTube',5,$changedFetch,'2026-07-28T12:00:00Z');
must($changed['capturesRecorded']===1&&$changed['duplicatesSkipped']===1,'Changement réel à observedAt identique');
$later=p50_metrics_collect_profile($pdo,'yt','YouTube',5,$changedFetch,'2026-07-28T13:00:00Z');
must($later['capturesRecorded']===2,'Nouvelle observation');
$config['metrics']['PASS50_YOUTUBE_API_KEY']='';
$fallbackFetch=function(string $url,array $headers=[]): array {
    $xml='<?xml version="1.0"?><feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"><entry><yt:videoId>fallback1</yt:videoId><title>Fallback video</title><published>2026-07-28T09:00:00Z</published></entry></feed>';
    return ['status'=>200,'body'=>$xml,'url'=>$url,'error'=>''];
};
$fallback=p50_metrics_collect_profile($pdo,'yt-fallback','YouTube',5,$fallbackFetch,'2026-07-28T13:30:00Z');
must($fallback['accountFound']&&$fallback['contentsFound']===1&&$fallback['capturesRecorded']===0,'Fallback public sans capture artificielle');
$config['metrics']['x_bearer_token']='';
$blocked=p50_metrics_collect_profile($pdo,'x-blocked','X',5,$fetch,'2026-07-28T13:30:00Z');
must($blocked['status']==='unavailable_or_blocked'&&!$blocked['accountFound']&&$blocked['capturesRecorded']===0,'X bloqué sans contournement');
$accountId=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_accounts WHERE profile_id='yt'");
$bad=p50_metrics_record_capture($pdo,['accountId'=>$accountId,'collector'=>'youtube_v1','sourceType'=>'fixture_invalid','observedAt'=>'2026-07-28T14:00:00Z','views'=>-3,'provenance'=>['collectorVersion'=>'1.0.0','platform'=>'YouTube','sourceType'=>'fixture','endpoint'=>'fixture','officialLink'=>'https://youtube.com/@Fixture','profileId'=>'yt','fetchedAt'=>'2026-07-28T14:00:00Z','httpStatus'=>200,'accessMode'=>'fixture','runUuid'=>'fixture']]);
must($bad['quarantined']===true&&$bad['usableMetricCount']===0,'Quarantaine');

$immutable=false;$captureId=(int)p50_metrics_value($pdo,"SELECT id FROM p50_metric_captures WHERE views=20 LIMIT 1");
try{$pdo->exec("UPDATE p50_metric_captures SET views=21 WHERE id=".$captureId);}catch(Throwable){$immutable=true;}must($immutable,'Immutabilité');
$partial=p50_metrics_start_run($pdo,['collector'=>'x_v1','platform'=>'X','triggerType'=>'fixture','metadata'=>[]]);p50_metrics_finish_run($pdo,$partial['runUuid'],'partial',['errorCount'=>1],'fixture partial',['rateLimited'=>true]);
$error=p50_metrics_start_run($pdo,['collector'=>'youtube_v1','platform'=>'YouTube','triggerType'=>'fixture','metadata'=>[]]);p50_metrics_finish_run($pdo,$error['runUuid'],'error',['errorCount'=>1],'fixture error',[]);
$status=p50_metrics_collectors_status($pdo);must($status['youtube']['captures']>=4&&$status['x']['captures']===2,'Diagnostic collecteurs');
$stored=(string)p50_metrics_value($pdo,"SELECT GROUP_CONCAT(CONCAT_WS(' ',provenance_json,metrics_json,metadata_json) SEPARATOR ' ') FROM p50_metric_captures");
must(!str_contains($stored,$youtubeCredential)&&!str_contains($stored,$xCredential)&&!str_contains(strtolower($stored),'authorization'),'Aucun secret stocké');
must((string)p50_metrics_value($pdo,"SELECT state_json FROM app_state WHERE id=1")==='{"sentinel":true}'&&(int)p50_metrics_value($pdo,"SELECT version FROM app_state WHERE id=1")===77,'app_state inchangé');
must((string)p50_metrics_value($pdo,"SELECT score FROM p50_profile_registry WHERE profile_id='yt'")==='42.50'&&(int)p50_metrics_value($pdo,"SELECT rank_position FROM p50_profile_registry WHERE profile_id='yt'")===7,'Score et rang inchangés');
echo json_encode(['ok'=>true,'youtube'=>$yt,'x'=>$x,'duplicateReplay'=>$again,'changedObservation'=>$changed,'laterObservation'=>$later,'youtubeFallback'=>$fallback,'xBlocked'=>$blocked,'quarantine'=>$bad,'collectors'=>$status],JSON_UNESCAPED_SLASHES).PHP_EOL;
