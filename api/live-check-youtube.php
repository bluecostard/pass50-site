<?php
declare(strict_types=1);
/*
 * À lancer par cron toutes les 5 minutes.
 * Configuration : api/config.php.
 * Fichier de configuration : api/data/youtube-channels.json
 */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/metrics-core.php';
$keyStatus = p50m_youtube_key_status();
$key = $keyStatus['configured'] ? p50m_youtube_key() : '';
$token = trim((string)(($GLOBALS['config']['data_engine']['live_admin_token'] ?? '') ?: ''));
if ($key === '') json_response(['error'=>'Clé YouTube absente dans api/config.php (metrics.PASS50_YOUTUBE_API_KEY).','configured'=>false],500);
if ($token === '') json_response(['error'=>'live_admin_token manquant dans api/config.php (data_engine).','configured'=>true,'keyLength'=>$keyStatus['keyLength']],500);
$channelsFile = __DIR__.'/data/youtube-channels.json';
$channels = json_decode(@file_get_contents($channelsFile) ?: '[]', true);
if (!is_array($channels)) $channels=[];
$streams=[];
foreach ($channels as $row) {
    $profileId = $row['profileId'] ?? '';
    $channelId = $row['channelId'] ?? '';
    if (!$profileId || !$channelId) continue;
    $url='https://www.googleapis.com/youtube/v3/search?part=snippet&channelId='.rawurlencode($channelId).'&eventType=live&type=video&maxResults=1&key='.rawurlencode($key);
    $ctx=stream_context_create(['http'=>['timeout'=>10,'user_agent'=>'PASS50/1.0']]);
    $raw=@file_get_contents($url,false,$ctx);
    $json=json_decode($raw ?: '',true);
    $item=$json['items'][0] ?? null;
    if (!$item) continue;
    $videoId=$item['id']['videoId'] ?? '';
    if (!$videoId) continue;
    $streams[]=[
      'id'=>'yt_'.$videoId,
      'profileId'=>$profileId,
      'platform'=>'YouTube',
      'url'=>'https://www.youtube.com/watch?v='.$videoId,
      'title'=>$item['snippet']['title'] ?? 'Direct YouTube',
      'status'=>'live',
      'source'=>'youtube_api',
      'startedAt'=>$item['snippet']['publishedAt'] ?? gmdate('c'),
      'endsAt'=>gmdate('c', time()+900)
    ];
}
$file=__DIR__.'/data/live-status.json';
$data=['liveStreams'=>$streams,'updatedAt'=>gmdate('c')];
file_put_contents($file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
echo json_encode(['ok'=>true,'count'=>count($streams),'updatedAt'=>$data['updatedAt']]);
