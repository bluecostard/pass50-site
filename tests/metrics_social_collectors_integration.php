<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/metrics-collectors-core.php';

$dsn=getenv('P50_TEST_DSN');if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function social_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$secrets=[];foreach(['tiktok','instagram','facebook','snapchat'] as $name)$secrets[$name]=hash('sha256',$name.'-fixture');
$config=['data_engine'=>['confidence_threshold'=>90],'metrics'=>[
  'tiktok_mode'=>'authorized_display','tiktok_access_token'=>$secrets['tiktok'],'tiktok_research_token'=>$secrets['tiktok'],'tiktok_research_approved'=>true,
  'instagram_enabled'=>true,'instagram_mode'=>'professional_authorized','instagram_access_token'=>$secrets['instagram'],
  'facebook_enabled'=>true,'facebook_mode'=>'page_authorized','facebook_access_token'=>$secrets['facebook'],
  'snapchat_enabled'=>true,'snapchat_mode'=>'public_profile_api','snapchat_access_token'=>$secrets['snapchat'],
]];
foreach(['p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_social_links','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190),alive TINYINT NOT NULL,score DECIMAL(6,2),rank_position INT)");
$pdo->exec("CREATE TABLE p50_social_links(profile_id VARCHAR(100),platform VARCHAR(32),normalized_url TEXT,confidence INT,status VARCHAR(24),PRIMARY KEY(profile_id,platform))");
$pdo->exec("CREATE TABLE app_state(id INT PRIMARY KEY,state_json LONGTEXT,version INT)");
$pdo->exec("INSERT INTO app_state VALUES(1,'{\"pr4Sentinel\":true}',88)");
$profiles=[['tt','TikTok','https://www.tiktok.com/@creator'],['ig','Instagram','https://www.instagram.com/creator'],['fb','Facebook','https://www.facebook.com/OfficialPage'],['sc','Snapchat','https://www.snapchat.com/add/creator']];
foreach($profiles as $index=>[$id,$platform,$url]){$pdo->prepare("INSERT INTO p50_profile_registry VALUES(?,?,1,?,?)")->execute([$id,$platform.' Fixture',50-$index,10+$index]);$pdo->prepare("INSERT INTO p50_social_links VALUES(?,?,?,98,'verified')")->execute([$id,$platform,$url]);}

$fetch=function(string $url,array $headers=[]): array {
    if(str_contains($url,'tiktokapis.com'))$data=['user'=>['open_id'=>'tt-open','username'=>'creator','follower_count'=>0,'following_count'=>3,'likes_count'=>50,'video_count'=>1],'videos'=>[['id'=>'tt-video-1','type'=>'video','description'=>'TikTok video','create_time'=>1722157200,'share_url'=>'https://www.tiktok.com/@creator/video/tt-video-1','duration'=>12,'view_count'=>100,'like_count'=>0,'comment_count'=>2,'share_count'=>3]]];
    elseif(str_contains($url,'instagram_business'))$data=['id'=>'ig-business-1','username'=>'creator','account_type'=>'BUSINESS','followers_count'=>0,'follows_count'=>4,'media_count'=>5,'media'=>[
      ['id'=>'ig-photo','media_type'=>'IMAGE','permalink'=>'https://instagram.com/p/photo1','timestamp'=>'2026-07-28T10:00:00Z','like_count'=>0,'comments_count'=>1],
      ['id'=>'ig-carousel','media_type'=>'CAROUSEL_ALBUM','permalink'=>'https://instagram.com/p/carousel1','timestamp'=>'2026-07-28T10:01:00Z'],
      ['id'=>'ig-video','media_type'=>'VIDEO','permalink'=>'https://instagram.com/p/video1','timestamp'=>'2026-07-28T10:02:00Z'],
      ['id'=>'ig-reel','media_type'=>'VIDEO','media_product_type'=>'REELS','permalink'=>'https://instagram.com/reel/reel1','timestamp'=>'2026-07-28T10:03:00Z','insights'=>['plays'=>20,'reach'=>15,'total_interactions'=>2]],
      ['id'=>'ig-story','media_type'=>'IMAGE','media_product_type'=>'STORY','story_authorized'=>true,'permalink'=>'https://instagram.com/stories/creator/story1','timestamp'=>'2026-07-28T10:04:00Z','insights'=>['views'=>10]],
      ['id'=>'ig-story-private','media_type'=>'IMAGE','media_product_type'=>'STORY','story_authorized'=>false,'permalink'=>'https://instagram.com/stories/creator/private','timestamp'=>'2026-07-28T10:05:00Z']
    ]];
    elseif(str_contains($url,'graph.facebook.com'))$data=['id'=>'fb-page-1','username'=>'OfficialPage','account_type'=>'PAGE','followers_count'=>0,'fan_count'=>20,'posts'=>[
      ['id'=>'fb-post','type'=>'post','permalink_url'=>'https://facebook.com/OfficialPage/posts/fb-post','metrics'=>['comments'=>2,'shares'=>1],'reactions'=>['total'=>8,'LIKE'=>3,'LOVE'=>5]],
      ['id'=>'fb-photo','type'=>'photo','permalink_url'=>'https://facebook.com/OfficialPage/posts/fb-photo'],
      ['id'=>'fb-video','type'=>'video','permalink_url'=>'https://facebook.com/OfficialPage/videos/fb-video','metrics'=>['video_views'=>30]],
      ['id'=>'fb-reel','format'=>'reel','permalink_url'=>'https://facebook.com/reel/fb-reel'],
      ['id'=>'fb-live','format'=>'live','permalink_url'=>'https://facebook.com/OfficialPage/videos/fb-live']
    ]];
    elseif(str_contains($url,'snapchat.com'))$data=['public_profile_id'=>'snap-public-1','username'=>'creator','subscriber_count'=>0,'spotlight_count'=>1,'story_count'=>2,'contents'=>[
      ['snap_id'=>'spot-1','type'=>'spotlight','permalink'=>'https://snapchat.com/spotlight/spot-1','views'=>40,'shares'=>2],
      ['snap_id'=>'story-1','type'=>'story','authorized'=>true,'permalink'=>'https://snapchat.com/story/story-1','views'=>10],
      ['snap_id'=>'story-private','type'=>'story','authorized'=>false],
      ['snap_id'=>'story-expired','type'=>'story','authorized'=>true,'expired'=>true,'permalink'=>'https://snapchat.com/story/story-expired','views'=>5]
    ]];
    else return ['status'=>404,'body'=>'{}','url'=>$url,'error'=>''];
    return ['status'=>200,'body'=>json_encode(['data'=>$data]),'url'=>$url,'error'=>''];
};

$results=[];foreach([['tt','TikTok'],['ig','Instagram'],['fb','Facebook'],['sc','Snapchat']] as [$id,$platform])$results[strtolower($platform)]=p50_metrics_collect_profile($pdo,$id,$platform,10,$fetch,'2026-07-28T12:00:00Z');
foreach($results as $platform=>$result)social_must($result['status']==='success'&&$result['accountFound'],"Collecte $platform");
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_accounts")===4,'Quatre comptes sociaux');
social_must((int)p50_metrics_value($pdo,"SELECT followers FROM p50_metric_captures WHERE platform='TikTok' AND content_id IS NULL")===0,'TikTok zéro conservé');
social_must(p50_metrics_value($pdo,"SELECT saves FROM p50_metric_captures WHERE platform='TikTok' AND content_id IS NOT NULL")===null,'TikTok favorite absent reste NULL');
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_contents WHERE content_type IN ('post','reel','story','spotlight','live')")>=5,'Types sociaux distincts');
social_must((int)p50_metrics_value($pdo,"SELECT likes FROM p50_metric_captures WHERE platform='Facebook' AND content_id IS NOT NULL LIMIT 1")===3,'Facebook LIKE distinct des réactions');
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_contents WHERE platform='Snapchat' AND status='expired'")===1,'Story expirée conservée');
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_contents WHERE platform='Instagram' AND platform_content_id='ig-story-private'")===0,'Story Instagram non autorisée ignorée');

$retry=p50_metrics_collect_profile($pdo,'tt','TikTok',10,$fetch,'2026-07-28T12:00:00Z');social_must($retry['capturesRecorded']===0&&$retry['duplicatesSkipped']===2,'Retry TikTok idempotent');
$later=p50_metrics_collect_profile($pdo,'tt','TikTok',10,$fetch,'2026-07-28T13:00:00Z');social_must($later['capturesRecorded']===2,'Nouvelle observation TikTok');
$config['metrics']['tiktok_access_token']='';$authRequired=p50_metrics_collect_profile($pdo,'tt','TikTok',5,$fetch,'2026-07-28T13:10:00Z');social_must($authRequired['status']==='authorization_required','TikTok autorisation requise');
$config['metrics']['tiktok_mode']='approved_research';$config['metrics']['tiktok_research_approved']=false;$researchDenied=p50_metrics_collect_profile($pdo,'tt','TikTok',5,$fetch,'2026-07-28T13:20:00Z');social_must($researchDenied['status']==='configuration_missing','Research non approuvée');
$config['metrics']['tiktok_research_approved']=true;$research=p50_metrics_collect_profile($pdo,'tt','TikTok',5,$fetch,'2026-07-28T13:30:00Z');social_must($research['status']==='success','Research approuvée');
$config['metrics']['tiktok_mode']='authorized_display';$config['metrics']['tiktok_access_token']=$secrets['tiktok'];
$forbidden=static fn(string $url,array $headers=[]): array=>['status'=>403,'body'=>'{}','url'=>$url,'error'=>''];
$limited=static fn(string $url,array $headers=[]): array=>['status'=>429,'body'=>'{}','url'=>$url,'error'=>''];
$blocked=static fn(string $url,array $headers=[]): array=>['status'=>404,'body'=>'{}','url'=>$url,'error'=>''];
$tt403=p50_metrics_collect_profile($pdo,'tt','TikTok',5,$forbidden,'2026-07-28T13:31:00Z');social_must($tt403['status']==='authorization_required','TikTok 403');
$tt429=p50_metrics_collect_profile($pdo,'tt','TikTok',5,$limited,'2026-07-28T13:32:00Z');social_must($tt429['status']==='rate_limited','TikTok 429');
$tt404=p50_metrics_collect_profile($pdo,'tt','TikTok',5,$blocked,'2026-07-28T13:33:00Z');social_must($tt404['status']==='unavailable_or_blocked','TikTok compte bloqué');
$config['metrics']['instagram_access_token']='';$igAuth=p50_metrics_collect_profile($pdo,'ig','Instagram',5,$fetch,'2026-07-28T13:40:00Z');social_must($igAuth['status']==='authorization_required','Instagram autorisation requise');
$config['metrics']['instagram_access_token']=$secrets['instagram'];
$ig403=p50_metrics_collect_profile($pdo,'ig','Instagram',5,$forbidden,'2026-07-28T13:50:00Z');social_must($ig403['status']==='authorization_required','Instagram 403');
$ig429=p50_metrics_collect_profile($pdo,'ig','Instagram',5,$limited,'2026-07-28T13:51:00Z');social_must($ig429['status']==='rate_limited','Instagram 429');
$personalInstagram=static fn(string $url,array $headers=[]): array=>['status'=>200,'body'=>json_encode(['data'=>['id'=>'ig-personal','username'=>'creator','account_type'=>'PERSONAL']]),'url'=>$url,'error'=>''];
$igPersonal=p50_metrics_collect_profile($pdo,'ig','Instagram',5,$personalInstagram,'2026-07-28T13:52:00Z');social_must($igPersonal['status']==='unsupported_account_type','Instagram personnel non pris en charge');
$fb403=p50_metrics_collect_profile($pdo,'fb','Facebook',5,$forbidden,'2026-07-28T14:00:00Z');social_must($fb403['status']==='authorization_required','Facebook 403');
$fb429=p50_metrics_collect_profile($pdo,'fb','Facebook',5,$limited,'2026-07-28T14:01:00Z');social_must($fb429['status']==='rate_limited','Facebook 429');
$personalFacebook=static fn(string $url,array $headers=[]): array=>['status'=>200,'body'=>json_encode(['data'=>['id'=>'fb-personal','username'=>'creator','account_type'=>'PERSONAL']]),'url'=>$url,'error'=>''];
$fbPersonal=p50_metrics_collect_profile($pdo,'fb','Facebook',5,$personalFacebook,'2026-07-28T14:02:00Z');social_must($fbPersonal['status']==='unsupported_account_type','Profil Facebook personnel non pris en charge');
$fb404=p50_metrics_collect_profile($pdo,'fb','Facebook',5,$blocked,'2026-07-28T14:03:00Z');social_must($fb404['status']==='unavailable_or_blocked','Page Facebook inaccessible');
$snap403=p50_metrics_collect_profile($pdo,'sc','Snapchat',5,$forbidden,'2026-07-28T14:10:00Z');social_must($snap403['status']==='authorization_required','Snapchat 403');
$snap429=p50_metrics_collect_profile($pdo,'sc','Snapchat',5,$limited,'2026-07-28T14:11:00Z');social_must($snap429['status']==='rate_limited','Snapchat 429');
$snap404=p50_metrics_collect_profile($pdo,'sc','Snapchat',5,$blocked,'2026-07-28T14:12:00Z');social_must($snap404['status']==='unavailable_or_blocked','Snapchat introuvable');
$config['metrics']['snapchat_access_token']='';$snapAuth=p50_metrics_collect_profile($pdo,'sc','Snapchat',5,$fetch,'2026-07-28T14:13:00Z');social_must($snapAuth['status']==='authorization_required','Snapchat autorisation requise');
$config['metrics']['snapchat_enabled']=false;$snapMissing=p50_metrics_collect_profile($pdo,'sc','Snapchat',5,$fetch,'2026-07-28T14:14:00Z');social_must($snapMissing['status']==='configuration_missing','Snapchat configuration absente');

$yt=p50_metrics_upsert_account($pdo,['profileId'=>'yt-sentinel','platform'=>'YouTube','platformAccountId'=>'UCsentinel','canonicalUrl'=>'https://youtube.com/channel/UCsentinel','sourceType'=>'fixture','provenance'=>['fixture'=>'pr4']]);
$x=p50_metrics_upsert_account($pdo,['profileId'=>'x-sentinel','platform'=>'X','platformAccountId'=>'x-sentinel','handle'=>'sentinel','canonicalUrl'=>'https://x.com/sentinel','sourceType'=>'fixture','provenance'=>['fixture'=>'pr4']]);
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(DISTINCT platform) FROM p50_metric_accounts")===6,'Six plateformes coexistent');
$stored=(string)p50_metrics_value($pdo,"SELECT GROUP_CONCAT(CONCAT_WS(' ',provenance_json,metrics_json,metadata_json) SEPARATOR ' ') FROM p50_metric_captures");
foreach($secrets as $secret)social_must(!str_contains($stored,$secret),'Secret absent des tables');
social_must((string)p50_metrics_value($pdo,"SELECT state_json FROM app_state WHERE id=1")==='{"pr4Sentinel":true}'&&(int)p50_metrics_value($pdo,"SELECT version FROM app_state WHERE id=1")===88,'app_state inchangé');
social_must((string)p50_metrics_value($pdo,"SELECT score FROM p50_profile_registry WHERE profile_id='tt'")==='50.00'&&(int)p50_metrics_value($pdo,"SELECT rank_position FROM p50_profile_registry WHERE profile_id='tt'")===10,'Score et rang inchangés');
$partialRun=p50_metrics_start_run($pdo,['collector'=>'pr4_fixture','platform'=>'TikTok','triggerType'=>'manual']);
p50_metrics_finish_run($pdo,$partialRun['runUuid'],'partial',['errorCount'=>1],'fixture partial');
$errorRun=p50_metrics_start_run($pdo,['collector'=>'pr4_fixture','platform'=>'Snapchat','triggerType'=>'manual']);
p50_metrics_finish_run($pdo,$errorRun['runUuid'],'error',['errorCount'=>1],'fixture error');
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_runs WHERE status='partial'")>=1,'Run partial');
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_runs WHERE status='error'")>=1,'Run error');
$diagnostic=p50_metrics_collectors_status($pdo);
echo json_encode(['ok'=>true,'results'=>$results,'retry'=>$retry,'later'=>$later,'statuses'=>[
  'authorizationRequired'=>$authRequired['status'],'researchDenied'=>$researchDenied['status'],
  'tiktok403'=>$tt403['status'],'tiktok429'=>$tt429['status'],'tiktok404'=>$tt404['status'],
  'instagram403'=>$ig403['status'],'instagram429'=>$ig429['status'],'instagramPersonal'=>$igPersonal['status'],
  'facebook403'=>$fb403['status'],'facebook429'=>$fb429['status'],'facebookPersonal'=>$fbPersonal['status'],'facebook404'=>$fb404['status'],
  'snapchat403'=>$snap403['status'],'snapchat429'=>$snap429['status'],'snapchat404'=>$snap404['status'],
  'snapchatAuthorization'=>$snapAuth['status'],'snapchatMissing'=>$snapMissing['status']
],'diagnostic'=>$diagnostic],JSON_UNESCAPED_SLASHES).PHP_EOL;
