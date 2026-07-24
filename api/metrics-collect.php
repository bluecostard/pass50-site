<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-core.php';
$user=auth_user();
require_role($user,'owner','admin');
require_method('POST');
set_time_limit(240);
p50m_ensure_schema();
p50m_sync_accounts_from_state();
$in=json_input();
$profileId=trim((string)($in['profileId']??''));
$platform=trim((string)($in['platform']??''));
$limit=max(1,min(25,(int)($in['limit']??10)));
$sql="SELECT * FROM p50_metric_accounts WHERE 1=1";$params=[];
if($profileId!==''){$sql.=" AND profile_id=?";$params[]=$profileId;}
if($platform!==''){$sql.=" AND platform=?";$params[]=$platform;}
$sql.=" ORDER BY COALESCE(last_collected_at,'1970-01-01') ASC LIMIT ".$limit;
$stmt=db()->prepare($sql);$stmt->execute($params);$accounts=$stmt->fetchAll();
$out=[];
foreach($accounts as $account)$out[]=p50m_collect_account($account);
$profileIds=array_values(array_unique(array_column($out,'profileId')));
$scores=[];foreach($profileIds as $id)$scores[]=p50m_calculate_profile((string)$id);
$published=p50m_publish_scores_to_state($scores);
json_response(['ok'=>true,'accountsSynced'=>count($accounts),'results'=>$out,'scores'=>$scores,'publishedProfiles'=>$published]);
