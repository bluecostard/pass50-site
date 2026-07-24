<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-core.php';
$user=auth_user();
require_role($user,'owner','admin');
p50m_ensure_schema();
p50m_sync_accounts_from_state();
$accounts=db()->query("SELECT profile_id,platform,profile_url,external_id,username,status,last_error,last_resolved_at,last_collected_at FROM p50_metric_accounts ORDER BY profile_id,platform")->fetchAll();
$summary=db()->query("SELECT platform,status,COUNT(*) total FROM p50_metric_accounts GROUP BY platform,status")->fetchAll();
$scores=db()->query("SELECT profile_id,period_key,score,confidence,coverage,calculated_at FROM p50_metric_criteria ORDER BY calculated_at DESC LIMIT 250")->fetchAll();
json_response(['ok'=>true,'accounts'=>$accounts,'summary'=>$summary,'scores'=>$scores,'youtubeConfigured'=>p50m_youtube_key()!=='','xConfigured'=>p50m_x_token()!=='']);
