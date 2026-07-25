<?php
declare(strict_types=1);
/*
 * À lancer par cron toutes les 5 minutes.
 * Configuration : api/config.php.
 * Fichier de configuration : api/data/youtube-channels.json
 */
require __DIR__.'/bootstrap.php';
$key = trim((string)($config['metrics']['PASS50_YOUTUBE_API_KEY']??''));
$token = trim((string)($config['data_engine']['live_admin_token']??''));
if ($key === '' || $token === '') json_response(['error'=>'Configuration serveur manquante'],500);
$configFile = __DIR__.'/data/youtube-channels.json';
$config = json_decode(@file_get_contents($configFile) ?: '[]', true);
if (!is_array($config)) $config=[];
$streams=[];
foreach ($config as $row) {
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
