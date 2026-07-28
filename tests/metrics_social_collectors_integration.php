<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/metrics-collectors-core.php';

$dsn=getenv('P50_TEST_DSN');if(!$dsn){fwrite(STDERR,"P50_TEST_DSN absent\n");exit(77);}
$pdo=new PDO($dsn,getenv('P50_TEST_DB_USER')?:'root',getenv('P50_TEST_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function social_must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function fixture_response(array $body,string $url,int $status=200): array {return ['status'=>$status,'body'=>json_encode($body,JSON_UNESCAPED_SLASHES),'url'=>$url,'error'=>''];}

$secrets=[];foreach(['tiktok','instagram','facebook','snapchat'] as $name)$secrets[$name]=hash('sha256',$name.'-fixture');
$config=['data_engine'=>['confidence_threshold'=>90],'metrics'=>[
  'tiktok_mode'=>'authorized_display','tiktok_access_token'=>$secrets['tiktok'],'tiktok_research_token'=>$secrets['tiktok'],'tiktok_research_approved'=>true,
  'instagram_enabled'=>true,'instagram_mode'=>'professional_authorized','instagram_access_token'=>$secrets['instagram'],'instagram_account_id'=>'ig-business-1','instagram_stories_authorized'=>true,
  'facebook_enabled'=>true,'facebook_mode'=>'page_authorized','facebook_access_token'=>$secrets['facebook'],'facebook_page_id'=>'fb-page-1',
  'snapchat_enabled'=>true,'snapchat_mode'=>'public_profile_api','snapchat_access_token'=>$secrets['snapchat'],'snapchat_stories_authorized'=>true,
]];
foreach(['p50_metric_captures','p50_metric_contents','p50_metric_jobs','p50_metric_runs','p50_metric_accounts','p50_metric_schema_migrations','p50_social_links','p50_profile_registry','app_state'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE p50_profile_registry(profile_id VARCHAR(100) PRIMARY KEY,public_name VARCHAR(190),alive TINYINT NOT NULL,score DECIMAL(6,2),rank_position INT)");
$pdo->exec("CREATE TABLE p50_social_links(profile_id VARCHAR(100),platform VARCHAR(32),normalized_url TEXT,confidence INT,status VARCHAR(24),PRIMARY KEY(profile_id,platform))");
$pdo->exec("CREATE TABLE app_state(id INT PRIMARY KEY,state_json LONGTEXT,version INT)");
$pdo->exec("INSERT INTO app_state VALUES(1,'{\"pr4Sentinel\":true}',88)");
$profiles=[['tt','TikTok','https://www.tiktok.com/@creator'],['ig','Instagram','https://www.instagram.com/creator'],['fb','Facebook','https://www.facebook.com/OfficialPage'],['sc','Snapchat','https://www.snapchat.com/add/creator']];
foreach($profiles as $index=>[$id,$platform,$url]){$pdo->prepare("INSERT INTO p50_profile_registry VALUES(?,?,1,?,?)")->execute([$id,$platform.' Fixture',50-$index,10+$index]);$pdo->prepare("INSERT INTO p50_social_links VALUES(?,?,?,98,'verified')")->execute([$id,$platform,$url]);}

$requests=[];
$fetch=function(string $url,array $headers=[],string $method='GET',?array $json=null) use(&$requests): array {
    $requests[]=['url'=>$url,'headers'=>$headers,'method'=>$method,'json'=>$json];$path=(string)parse_url($url,PHP_URL_PATH);parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    if($path==='/v2/user/info/')return fixture_response(['data'=>['user'=>['open_id'=>'tt-open','display_name'=>'Creator','follower_count'=>0,'following_count'=>3,'likes_count'=>50,'video_count'=>2]],'error'=>['code'=>'ok']],$url);
    if($path==='/v2/video/list/'){
        social_must($method==='POST','TikTok Display doit utiliser POST');social_must(in_array('Content-Type: application/json',$headers,true),'TikTok Display JSON');
        social_must(!isset($query['username']),'TikTok Display username interdit');social_must(isset($query['fields'])&&isset($json['max_count']),'TikTok Display fields/body');
        $second=($json['cursor']??0)===10;
        return fixture_response(['data'=>['videos'=>[[
          'id'=>$second?'tt-video-2':'tt-video-1','title'=>'TikTok video','create_time'=>1722157200,'share_url'=>'https://www.tiktok.com/@creator/video/'.($second?'tt-video-2':'tt-video-1'),
          'duration'=>12,'view_count'=>$second?200:100,'like_count'=>0,'comment_count'=>2,'share_count'=>3
        ]],'cursor'=>$second?20:10,'has_more'=>!$second],'error'=>['code'=>'ok']],$url);
    }
    if($path==='/v2/research/user/info/'){social_must($method==='POST'&&($json['username']??'')==='creator','TikTok Research user POST');return fixture_response(['data'=>['username'=>'creator','follower_count'=>0,'following_count'=>3,'likes_count'=>50,'video_count'=>1],'error'=>['code'=>'ok']],$url);}
    if($path==='/v2/research/video/query/'){
        social_must($method==='POST'&&isset($json['query']['and'],$json['start_date'],$json['end_date'],$json['cursor']),'TikTok Research query/date/cursor');
        return fixture_response(['data'=>['videos'=>[['id'=>'tt-research-1','username'=>'creator','video_description'=>'Research','create_time'=>1722157200,'view_count'=>300,'like_count'=>4,'comment_count'=>2,'share_count'=>1,'favorites_count'=>0,'video_duration'=>15]],'cursor'=>1,'has_more'=>false,'search_id'=>'search-1'],'error'=>['code'=>'ok']],$url);
    }
    if($path==='/v22.0/ig-business-1')return fixture_response(['id'=>'ig-business-1','username'=>'creator','account_type'=>'BUSINESS','followers_count'=>0,'follows_count'=>4,'media_count'=>2],$url);
    if($path==='/v22.0/ig-business-1/media'){
        $second=($query['after']??'')==='IG2';$id=$second?'ig-reel':'ig-photo';
        return fixture_response(['data'=>[['id'=>$id,'media_type'=>$second?'VIDEO':'IMAGE','media_product_type'=>$second?'REELS':'FEED','permalink'=>$second?'https://instagram.com/reel/reel1':'https://instagram.com/p/photo1','timestamp'=>'2026-07-28T10:00:00Z','like_count'=>0,'comments_count'=>1]],'paging'=>['cursors'=>['after'=>$second?'':'IG2']]],$url);
    }
    if(preg_match('#^/v22\.0/(ig-photo|ig-reel)/insights$#',$path,$m))return fixture_response(['data'=>[
      ['name'=>'reach','period'=>'lifetime','values'=>[['value'=>15]]],['name'=>'plays','period'=>'lifetime','values'=>[['value'=>20]]],
      ['name'=>'views','period'=>'lifetime','values'=>[['value'=>$m[1]==='ig-reel'?20:0]]],['name'=>'total_interactions','period'=>'lifetime','values'=>[['value'=>2]]]
    ]],$url);
    if($path==='/v22.0/fb-page-1')return fixture_response(['id'=>'fb-page-1','name'=>'Official Page','username'=>'OfficialPage','followers_count'=>0,'fan_count'=>20],$url);
    if($path==='/v22.0/fb-page-1/posts'){
        social_must(str_contains((string)($query['fields']??''),'comments.limit(0).summary(true)'),'Facebook comments summary');
        social_must(str_contains((string)($query['fields']??''),'reactions.type(LIKE)'),'Facebook LIKE explicite');
        $second=($query['after']??'')==='FB2';$id=$second?'fb-live':'fb-post';
        return fixture_response(['data'=>[['id'=>$id,'message'=>'Post','created_time'=>'2026-07-28T10:00:00Z','permalink_url'=>'https://facebook.com/OfficialPage/posts/'.$id,'status_type'=>$second?'added_video':'mobile_status_update',
          'attachments'=>['data'=>[['media_type'=>$second?'live_video':'photo']]],'comments'=>['summary'=>['total_count'=>2]],'shares'=>['count'=>1],
          'like_reactions'=>['summary'=>['total_count'=>3]],'reactions'=>['summary'=>['total_count'=>8]]
        ]],'paging'=>['cursors'=>['after'=>$second?'':'FB2']]],$url);
    }
    if(preg_match('#^/v22\.0/(fb-post|fb-live)/insights$#',$path))return fixture_response(['data'=>[
      ['name'=>'post_impressions_unique','values'=>[['value'=>30]]],['name'=>'post_clicks','values'=>[['value'=>4]]],['name'=>'post_video_views','values'=>[['value'=>20]]]
    ]],$url);
    if($path==='/public/v1/public_profiles/search'){social_must(($query['query']??'')==='creator','Snapchat search query');return fixture_response(['public_profiles'=>[['public_profile'=>['id'=>'snap-public-1','snap_user_name'=>'creator']]]],$url);}
    if($path==='/public/v1/public_profiles/snap-public-1')return fixture_response(['public_profile'=>['id'=>'snap-public-1','snap_user_name'=>'creator','subscriber_count'=>0]],$url);
    if($path==='/public/v1/public_profiles/snap-public-1/spotlights')return fixture_response(['spotlights'=>[['spotlight'=>['id'=>'spot-1','share_url'=>'https://snapchat.com/spotlight/spot-1','created_at'=>'2026-07-28T10:00:00Z']]],'page'=>[]],$url);
    if($path==='/public/v1/public_profiles/snap-public-1/spotlights/spot-1/stats')return fixture_response(['assets'=>[['timeseries'=>[['stats'=>[['metric'=>'VIEWS','value'=>40],['metric'=>'SPOTLIGHT_SHARES','value'=>2]]]]]]],$url);
    if($path==='/public/v1/public_profiles/snap-public-1/saved_stories')return fixture_response(['saved_stories'=>[['saved_story'=>['id'=>'saved-1','share_url'=>'https://snapchat.com/story/saved-1','created_at'=>'2026-07-28T10:00:00Z']]],'page'=>[]],$url);
    if($path==='/public/v1/public_profiles/snap-public-1/saved_stories/saved-1/stats')return fixture_response(['assets'=>[['timeseries'=>[['stats'=>['SAVED_STORY_VIEWS'=>10]]]]]],$url);
    if($path==='/v1/public_profiles/snap-public-1/stories')return fixture_response(['stories'=>[['story'=>['id'=>'story-expired','expired'=>true,'created_at'=>'2026-07-27T10:00:00Z']]],'page'=>[]],$url);
    if($path==='/v1/public_profiles/snap-public-1/stories/story-expired/stats')return fixture_response(['assets'=>[['timeseries'=>[['stats'=>['STORY_VIEWS'=>5]]]]]],$url);
    return fixture_response(['error'=>['message'=>'fixture endpoint absent']],$url,404);
};

$results=[];
foreach([['tt','TikTok',3],['ig','Instagram',3],['fb','Facebook',3],['sc','Snapchat',5]] as [$id,$platform,$limit])$results[strtolower($platform)]=p50_metrics_collect_profile($pdo,$id,$platform,$limit,$fetch,'2026-07-28T12:00:00Z');
foreach($results as $platform=>$result)social_must(in_array($result['status'],['success','partial'],true)&&$result['accountFound'],"Collecte $platform");
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_accounts")===4,'Quatre comptes sociaux');
social_must((int)p50_metrics_value($pdo,"SELECT followers FROM p50_metric_captures WHERE platform='TikTok' AND content_id IS NULL")===0,'TikTok zéro conservé');
social_must(p50_metrics_value($pdo,"SELECT saves FROM p50_metric_captures WHERE platform='TikTok' AND platform='TikTok' AND content_id IS NOT NULL LIMIT 1")===null,'TikTok métrique absente reste NULL');
social_must((int)p50_metrics_value($pdo,"SELECT likes FROM p50_metric_captures WHERE platform='Facebook' AND content_id IS NOT NULL LIMIT 1")===3,'Facebook LIKE distinct des réactions');
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(*) FROM p50_metric_contents WHERE platform='Snapchat' AND status='expired'")===1,'Story expirée conservée');

$retry=p50_metrics_collect_profile($pdo,'tt','TikTok',3,$fetch,'2026-07-28T12:00:00Z');social_must($retry['capturesRecorded']===0&&$retry['duplicatesSkipped']>=3,'Retry TikTok idempotent');
$later=p50_metrics_collect_profile($pdo,'tt','TikTok',3,$fetch,'2026-07-28T13:00:00Z');social_must($later['capturesRecorded']>=3,'Nouvelle observation TikTok');
$config['metrics']['tiktok_mode']='approved_research';$research=p50_metrics_collect_profile($pdo,'tt','TikTok',2,$fetch,'2026-07-28T14:00:00Z');social_must($research['status']==='success','TikTok Research');

$postJson=array_values(array_filter($requests,static fn($request)=>$request['method']==='POST'));
social_must(count($postJson)>=3,'Requêtes POST JSON observées');
foreach($postJson as $request)social_must(in_array('Content-Type: application/json',$request['headers'],true)&&is_array($request['json']),'POST JSON conforme');
foreach($requests as $request)social_must(!str_contains($request['url'],$secrets['tiktok'])&&!str_contains($request['url'],$secrets['instagram'])&&!str_contains($request['url'],$secrets['facebook'])&&!str_contains($request['url'],$secrets['snapchat']),'Secret absent des URL');

$yt=p50_metrics_upsert_account($pdo,['profileId'=>'yt-sentinel','platform'=>'YouTube','platformAccountId'=>'UCsentinel','canonicalUrl'=>'https://youtube.com/channel/UCsentinel','sourceType'=>'fixture','provenance'=>['fixture'=>'pr4']]);
$x=p50_metrics_upsert_account($pdo,['profileId'=>'x-sentinel','platform'=>'X','platformAccountId'=>'x-sentinel','handle'=>'sentinel','canonicalUrl'=>'https://x.com/sentinel','sourceType'=>'fixture','provenance'=>['fixture'=>'pr4']]);
social_must((int)p50_metrics_value($pdo,"SELECT COUNT(DISTINCT platform) FROM p50_metric_accounts")===6,'Six plateformes coexistent');
$stored=(string)p50_metrics_value($pdo,"SELECT GROUP_CONCAT(CONCAT_WS(' ',provenance_json,metrics_json,metadata_json) SEPARATOR ' ') FROM p50_metric_captures");
foreach($secrets as $secret)social_must(!str_contains($stored,$secret),'Secret absent des tables');
social_must((string)p50_metrics_value($pdo,"SELECT state_json FROM app_state WHERE id=1")==='{"pr4Sentinel":true}'&&(int)p50_metrics_value($pdo,"SELECT version FROM app_state WHERE id=1")===88,'app_state inchangé');
social_must((string)p50_metrics_value($pdo,"SELECT score FROM p50_profile_registry WHERE profile_id='tt'")==='50.00'&&(int)p50_metrics_value($pdo,"SELECT rank_position FROM p50_profile_registry WHERE profile_id='tt'")===10,'Score et rang inchangés');

echo json_encode(['ok'=>true,'results'=>$results,'retry'=>$retry,'later'=>$later,'research'=>$research,'requestCount'=>count($requests),'diagnostic'=>p50_metrics_collectors_status($pdo)],JSON_UNESCAPED_SLASHES).PHP_EOL;
