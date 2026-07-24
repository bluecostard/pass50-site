<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-core.php';
set_time_limit(300);
$expected=defined('PASS50_METRICS_CRON_TOKEN')?(string)PASS50_METRICS_CRON_TOKEN:(string)(getenv('PASS50_METRICS_CRON_TOKEN')?:'');
$provided=(string)($_GET['token']??($_SERVER['HTTP_X_PASS50_CRON_TOKEN']??''));
if($expected===''||!hash_equals($expected,$provided))json_response(['error'=>'Jeton cron invalide.'],403);
p50m_ensure_schema();p50m_sync_accounts_from_state();
$limit=max(1,min(40,(int)($_GET['limit']??20)));
$stmt=db()->query("SELECT * FROM p50_metric_accounts WHERE platform IN ('YouTube','X') ORDER BY COALESCE(last_collected_at,'1970-01-01') ASC LIMIT ".$limit);
$results=[];foreach($stmt->fetchAll() as $account)$results[]=p50m_collect_account($account);
$ids=array_values(array_unique(array_column($results,'profileId')));
$scores=[];foreach($ids as $id)$scores[]=p50m_calculate_profile((string)$id);
$published=p50m_publish_scores_to_state($scores);
json_response(['ok'=>true,'processed'=>count($results),'publishedProfiles'=>$published,'results'=>$results]);
