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

$current=db()->prepare("SELECT stream_key,url FROM p50_live_streams WHERE profile_id=? AND platform=? AND status IN ('live','unconfirmed') ORDER BY (url=?) DESC,last_seen_at DESC LIMIT 1");
$current->execute([$profileId,$platform,$url]);
$row=$current->fetch()?:null;
$live=['profileId'=>$profileId,'platform'=>$platform,'url'=>$url];
$key=is_array($row)&&!empty($row['stream_key'])?(string)$row['stream_key']:p50_live_v4_stream_key($live);
$profileKey=p50_live_v4_profile_dismiss_key($profileId,$platform);
$resolvedUrl=is_array($row)&&!empty($row['url'])?(string)$row['url']:$url;
$urlHash=hash('sha256',strtolower(rtrim($resolvedUrl,'/')));
$stmt=db()->prepare("INSERT INTO p50_live_dismissals(stream_key,profile_id,platform,url_hash,dismissed_by,reason,dismissed_at) VALUES(?,?,?,?,?,'false_positive',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE dismissed_by=VALUES(dismissed_by),reason='false_positive',dismissed_at=UTC_TIMESTAMP()");
$stmt->execute([$key,$profileId,$platform,$urlHash,(string)$user['id']]);
$stmt->execute([$profileKey,$profileId,$platform,$urlHash,(string)$user['id']]);
$metadata=json_encode(['endReason'=>'manually_dismissed','dismissedAt'=>gmdate(DATE_ATOM),'dismissedBy'=>(string)$user['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$end=db()->prepare("UPDATE p50_live_streams SET status='ended',ended_at=COALESCE(ended_at,UTC_TIMESTAMP()),metadata=JSON_MERGE_PATCH(COALESCE(metadata,'{}'),?) WHERE profile_id=? AND platform=? AND source IN ('automatic','meta_authorized') AND status IN ('live','unconfirmed')");
$end->execute([$metadata,$profileId,$platform]);
$health=db()->prepare("UPDATE p50_live_source_health SET last_state='offline',last_error='manually_dismissed',last_checked_at=UTC_TIMESTAMP() WHERE profile_id=? AND platform=?");
$health->execute([$profileId,$platform]);

json_response(['ok'=>true,'dismissed'=>true,'streamKey'=>$key,'profileDismissKey'=>$profileKey,'profileId'=>$profileId,'platform'=>$platform]);
