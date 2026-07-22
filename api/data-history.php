<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('GET');
p50_de_ensure_schema();
$profileId=trim((string)($_GET['profileId']??''));
$period=(string)($_GET['period']??'2H');
$days=max(1,min(365,(int)($_GET['days']??30)));
if($profileId==='')json_response(['error'=>'Profil manquant.'],422);
$stmt=db()->prepare('SELECT rank_position,trend_score,rank_delta,badges,data_confidence,captured_at FROM p50_ranking_snapshots WHERE profile_id=? AND period_key=? AND captured_at>=DATE_SUB(NOW(),INTERVAL ? DAY) ORDER BY captured_at ASC');
$stmt->execute([$profileId,$period,$days]);
$items=$stmt->fetchAll();
foreach($items as &$item)$item['badges']=decode_json_column($item['badges']??null,[]);
json_response(['ok'=>true,'profileId'=>$profileId,'period'=>$period,'days'=>$days,'items'=>$items]);
