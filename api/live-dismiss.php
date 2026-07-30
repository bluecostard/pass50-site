<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/live-radar-v4-core.php';

require_method('POST');
$user=auth_user();
require_role($user,'owner','admin');
p50_live_v4_ensure_schema();
p50_live_v4_ensure_dismissals();

$input=json_input();
$profileId=trim((string)($input['profileId']??''));
$platform=trim((string)($input['platform']??''));
$url=trim((string)($input['url']??''));
if($profileId===''||!in_array($platform,P50_LIVE_V4_PLATFORMS,true)||!p50_public_http_url($url))json_response(['error'=>'Direct invalide.'],422);

$live=['profileId'=>$profileId,'platform'=>$platform,'url'=>$url];
$key=p50_live_v4_stream_key($live);
$urlHash=hash('sha256',strtolower(rtrim($url,'/')));
$stmt=db()->prepare("INSERT INTO p50_live_dismissals(stream_key,profile_id,platform,url_hash,dismissed_by,reason,dismissed_at) VALUES(?,?,?,?,?,'false_positive',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE dismissed_by=VALUES(dismissed_by),reason='false_positive',dismissed_at=UTC_TIMESTAMP()");
$stmt->execute([$key,$profileId,$platform,$urlHash,(string)$user['id']]);
$metadata=json_encode(['endReason'=>'manually_dismissed','dismissedAt'=>gmdate(DATE_ATOM),'dismissedBy'=>(string)$user['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$end=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()),metadata=JSON_MERGE_PATCH(COALESCE(metadata,'{}'),?) WHERE stream_key=?");
$end->execute([$metadata,$key]);
$health=db()->prepare("UPDATE p50_live_source_health SET last_state='offline',last_error='manually_dismissed',last_checked_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?");
$health->execute([$profileId,$platform]);

json_response(['ok'=>true,'dismissed'=>true,'streamKey'=>$key,'profileId'=>$profileId,'platform'=>$platform]);