<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/content-intelligence-core.php';

if($_SERVER['REQUEST_METHOD']!=='GET')json_response(['error'=>'Méthode refusée.'],405);
$period=trim((string)($_GET['period']??'24h'));
if(!array_key_exists($period,p50_ci_periods()))json_response(['error'=>'Période invalide.'],422);
$profileId=trim((string)($_GET['profileId']??''));
if($profileId!==''&&(strlen($profileId)>100||!preg_match('/^[A-Za-z0-9._-]+$/',$profileId)))json_response(['error'=>'Profil invalide.'],422);
$newsLimit=max(1,min(30,(int)($_GET['newsLimit']??12)));
$trendMaxAgeHours=['2h'=>24,'24h'=>72,'48h'=>120,'7d'=>240,'15d'=>384][$period];
$trendFreshSince=gmdate('Y-m-d H:i:s',time()-$trendMaxAgeHours*3600);

function p50_content_feed_facebook_url(string $platform,string $url): ?array {
    if(strcasecmp(trim($platform),'Facebook')!==0)return null;
    $url=trim($url);if($url===''||!preg_match('~^https://~i',$url))return null;
    $parts=parse_url($url);if(!is_array($parts))return null;
    $host=strtolower((string)($parts['host']??''));
    if(!($host==='facebook.com'||str_ends_with($host,'.facebook.com')||$host==='fb.watch'))return null;
    return ['host'=>$host,'path'=>strtolower((string)($parts['path']??'/'))];
}

function p50_content_feed_facebook_explicit_video_url(string $platform,string $url): bool {
    $parts=p50_content_feed_facebook_url($platform,$url);if($parts===null)return false;
    if($parts['host']==='fb.watch')return true;
    return preg_match('~(?:^|/)(?:watch/?|reel/|share/(?:v|r)/|[^/]+/videos/)(?:|[^?]*)$~i',(string)$parts['path'])===1;
}

function p50_content_feed_facebook_embed_type(string $platform,string $url): ?string {
    $parts=p50_content_feed_facebook_url($platform,$url);if($parts===null)return null;
    return p50_content_feed_facebook_explicit_video_url($platform,$url)?'video':'post';
}

function p50_content_feed_facebook_playable(string $platform,string $contentType,string $url=''): bool {
    if(p50_content_feed_facebook_url($platform,$url)===null)return false;
    if(in_array(strtolower(trim($contentType)),['video','reel','live'],true))return true;
    return p50_content_feed_facebook_explicit_video_url($platform,$url);
}

$pdo=db();
if(!p50_ci_table_ready($pdo)){
  if($profileId===''){
    header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
  }else{
    header('Cache-Control: private, max-age=15');
  }
  json_response([
    'ok'=>true,'ready'=>false,'version'=>P50_CONTENT_INTELLIGENCE_VERSION,
    'contract'=>defined('P50_PUBLIC_FEED_CONTRACT')?P50_PUBLIC_FEED_CONTRACT:'PASS50-PUBLIC-FEED-V1',
    'period'=>$period,
    'trends'=>[],'news'=>[],'generatedAt'=>gmdate('c'),'message'=>'Le premier calcul des contenus tendance est en attente.'
]);
}

$trendStmt=$pdo->prepare("SELECT t.rank_position,t.previous_rank,t.rank_delta,t.score,t.confidence,t.view_delta,t.interaction_delta,
  t.share_delta,t.velocity,t.acceleration,t.follower_count,t.cluster_platform_count,t.badge,t.calculated_at,
  c.id content_id,c.profile_id,c.platform,c.content_type,c.canonical_url,c.title,c.published_at,c.first_seen_at,c.platform_content_id,c.metadata_json,
  r.public_name,n.thumbnail_url
  FROM p50_content_trend_current t
  JOIN p50_metric_contents c ON c.id=t.content_id
  JOIN p50_profile_registry r ON BINARY r.profile_id=BINARY c.profile_id
  LEFT JOIN p50_news_items n ON n.content_id=c.id AND n.validation_status='published'
  WHERE t.period_key=? AND c.status='active' AND r.alive=1
    AND COALESCE(c.published_at,c.first_seen_at)>=?
  ORDER BY t.rank_position LIMIT 80");
$trendStmt->execute([$period,$trendFreshSince]);
$trends=[];$perProfile=[];
foreach($trendStmt->fetchAll() as $row){
    $pid=(string)$row['profile_id'];
    if(($perProfile[$pid]??0)>=2)continue;
    $metadata=p50_ci_decode((string)$row['metadata_json']);
    $thumbnail=trim((string)($row['thumbnail_url']??''));
    if($thumbnail==='')$thumbnail=(string)(p50_ci_thumbnail((string)$row['platform'],(string)$row['canonical_url'],$row['platform_content_id']!==null?(string)$row['platform_content_id']:null,$metadata)??'');
    $title=trim((string)$row['title']);
    $titleLength=function_exists('mb_strlen')?mb_strlen($title,'UTF-8'):strlen($title);
    if(strcasecmp((string)$row['platform'],'Facebook')===0&&$titleLength<12&&$thumbnail==='')continue;
    $publishedSource=$row['published_at']?:$row['first_seen_at'];
    $playable=p50_content_feed_facebook_playable((string)$row['platform'],(string)$row['content_type'],(string)$row['canonical_url']);
    $trends[]=[
        'rank'=>count($trends)+1,'sourceRank'=>(int)$row['rank_position'],'previousRank'=>$row['previous_rank']===null?null:(int)$row['previous_rank'],
        'rankDelta'=>$row['rank_delta']===null?null:(int)$row['rank_delta'],'contentId'=>(int)$row['content_id'],
        'profileId'=>$pid,'name'=>(string)$row['public_name'],'platform'=>(string)$row['platform'],'contentType'=>(string)$row['content_type'],
        'title'=>$title,'url'=>(string)$row['canonical_url'],'thumbnailUrl'=>$thumbnail,
        'publishedAt'=>$publishedSource?gmdate('c',strtotime((string)$publishedSource.' UTC')):null,
        'score'=>(float)$row['score'],'confidence'=>(float)$row['confidence'],'badge'=>(string)$row['badge'],
        'viewDelta'=>(int)$row['view_delta'],'interactionDelta'=>(int)$row['interaction_delta'],'shareDelta'=>(int)$row['share_delta'],
        'velocity'=>(float)$row['velocity'],'acceleration'=>(float)$row['acceleration'],
        'followers'=>$row['follower_count']===null?null:(int)$row['follower_count'],'clusterPlatformCount'=>(int)$row['cluster_platform_count'],
        'calculatedAt'=>gmdate('c',strtotime((string)$row['calculated_at'].' UTC')),
        'readableInPass50'=>strcasecmp((string)$row['platform'],'Facebook')===0,
        'playableInPass50'=>$playable,
        'facebookEmbedType'=>$playable?p50_content_feed_facebook_embed_type((string)$row['platform'],(string)$row['canonical_url']):null,
    ];
    $perProfile[$pid]=($perProfile[$pid]??0)+1;
    if(count($trends)>=5)break;
}

$news=[];
$officialNewsHoursApplied=72;
$officialNewsUsedFallback=false;
if($profileId!==''){
    $officialCountStmt=$pdo->prepare("SELECT COUNT(*) FROM p50_news_items n
      WHERE BINARY n.profile_id=BINARY ? AND n.validation_status='published' AND n.is_official=1
        AND (n.expires_at IS NULL OR n.expires_at>UTC_TIMESTAMP())
        AND COALESCE(n.source_published_at,n.pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 72 HOUR)");
    $officialCountStmt->execute([$profileId]);
    $hasOfficial72h=(int)$officialCountStmt->fetchColumn()>0;
    // FI active → 72 h. Sinon fallback 7 jours pour éviter les fiches vides alors que le classement bouge.
    $officialNewsHoursApplied=$hasOfficial72h?72:168;
    $officialNewsUsedFallback=!$hasOfficial72h;

    $newsStmt=$pdo->prepare("SELECT n.id,n.content_id,n.platform,n.item_type,n.canonical_url,n.title,n.thumbnail_url,
      n.source_published_at,n.source_type,n.confidence,n.is_official,n.pass50_published_at,
      t.score trend_score,t.badge trend_badge,t.rank_position trend_rank
      FROM p50_news_items n
      LEFT JOIN p50_content_trend_current t ON t.content_id=n.content_id AND t.period_key=?
      WHERE BINARY n.profile_id=BINARY ? AND n.validation_status='published'
        AND (n.expires_at IS NULL OR n.expires_at>UTC_TIMESTAMP())
        AND (
          (n.is_official=1 AND COALESCE(n.source_published_at,n.pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ".$officialNewsHoursApplied." HOUR))
          OR
          (n.is_official=0 AND COALESCE(n.source_published_at,n.pass50_published_at)>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY))
        )
      ORDER BY COALESCE(n.source_published_at,n.pass50_published_at) DESC,n.id DESC LIMIT ".$newsLimit);
    $newsStmt->execute([$period,$profileId]);
    foreach($newsStmt->fetchAll() as $row){
        $title=trim((string)$row['title']);$thumbnail=trim((string)($row['thumbnail_url']??''));
        $titleLength=function_exists('mb_strlen')?mb_strlen($title,'UTF-8'):strlen($title);
        if(strcasecmp((string)$row['platform'],'Facebook')===0&&$titleLength<12&&$thumbnail==='')continue;
        $playable=p50_content_feed_facebook_playable((string)$row['platform'],(string)$row['item_type'],(string)$row['canonical_url']);
        $news[]=[
            'id'=>(int)$row['id'],'contentId'=>$row['content_id']===null?null:(int)$row['content_id'],'platform'=>(string)$row['platform'],
            'itemType'=>(string)$row['item_type'],'url'=>(string)$row['canonical_url'],'title'=>$title,
            'thumbnailUrl'=>$thumbnail,'publishedAt'=>$row['source_published_at']?gmdate('c',strtotime((string)$row['source_published_at'].' UTC')):null,
            'sourceType'=>(string)$row['source_type'],'confidence'=>(int)$row['confidence'],'official'=>(bool)$row['is_official'],
            'trendScore'=>$row['trend_score']===null?null:(float)$row['trend_score'],'trendBadge'=>$row['trend_badge']?:null,
            'trendRank'=>$row['trend_rank']===null?null:(int)$row['trend_rank'],
            'readableInPass50'=>strcasecmp((string)$row['platform'],'Facebook')===0,
            'playableInPass50'=>$playable,
            'facebookEmbedType'=>$playable?p50_content_feed_facebook_embed_type((string)$row['platform'],(string)$row['canonical_url']):null,
        ];
    }
}

$lastRun=$pdo->query("SELECT run_uuid,status,contents_considered,rows_written,finished_at FROM p50_content_trend_runs WHERE status='success' ORDER BY finished_at DESC,id DESC LIMIT 1")->fetch();
$runFinishedAt=$lastRun&&$lastRun['finished_at']?strtotime((string)$lastRun['finished_at'].' UTC'):false;
$trendAgeMinutes=$runFinishedAt===false?null:max(0,(int)floor((time()-$runFinishedAt)/60));
$trendDataStale=$trendAgeMinutes!==null&&$trendAgeMinutes>30;
if($profileId===''){
  header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
}else{
  header('Cache-Control: private, max-age=15');
}
json_response([
    'ok'=>true,'ready'=>true,'version'=>P50_CONTENT_INTELLIGENCE_VERSION,
    'contract'=>defined('P50_PUBLIC_FEED_CONTRACT')?P50_PUBLIC_FEED_CONTRACT:'PASS50-PUBLIC-FEED-V1',
    'period'=>$period,
    'periods'=>array_keys(p50_ci_periods()),'trends'=>$trends,'news'=>$news,
    'trendDataStale'=>$trendDataStale,'trendAgeMinutes'=>$trendAgeMinutes,
    'message'=>$trendDataStale?'Mise à jour des tendances en attente. Le dernier Top 5 valide reste affiché.':null,
    'rules'=>[
        'maxPerProfile'=>2,'topLimit'=>5,'officialContentAutomatic'=>true,'externalNewsHumanValidation'=>true,
        'officialNewsMaxAgeHours'=>72,'officialNewsFallbackMaxAgeHours'=>168,
        'officialNewsHoursApplied'=>$officialNewsHoursApplied,'officialNewsUsedFallback'=>$officialNewsUsedFallback,
        'externalNewsMaxAgeDays'=>7,'trendMaxAgeHours'=>$trendMaxAgeHours,'maxTrendRunAgeMinutes'=>30,
        'staleTrendsRemainVisible'=>true,
        'facebookPreviewInPass50'=>true,'facebookVideoPlaybackInPass50'=>true,'facebookEmbedRouting'=>true,
    ],
    'run'=>$lastRun?['runUuid'=>(string)$lastRun['run_uuid'],'contentsConsidered'=>(int)$lastRun['contents_considered'],'rowsWritten'=>(int)$lastRun['rows_written'],'finishedAt'=>gmdate('c',strtotime((string)$lastRun['finished_at'].' UTC'))]:null,
    'generatedAt'=>gmdate('c'),
]);
