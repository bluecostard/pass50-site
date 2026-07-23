<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('GET');
p50_de_ensure_schema();
$threshold=p50_de_threshold();
$profiles=(int)db()->query('SELECT COUNT(*) FROM p50_profile_registry WHERE alive=1')->fetchColumn();
$eligible=(int)db()->query('SELECT COUNT(*) FROM p50_profile_registry WHERE alive=1 AND eligible=1')->fetchColumn();
$stmt=db()->prepare("SELECT COUNT(*) FROM p50_facts WHERE status='verified' AND confidence>=?");$stmt->execute([$threshold]);$verifiedFacts=(int)$stmt->fetchColumn();
$stmt=db()->prepare("SELECT COUNT(*) FROM p50_social_links WHERE status='verified' AND confidence>=?");$stmt->execute([$threshold]);$verifiedLinks=(int)$stmt->fetchColumn();
$lastRun=db()->query('SELECT status,collector,started_at,finished_at FROM p50_collection_runs ORDER BY started_at DESC LIMIT 1')->fetch()?:null;
json_response(['ok'=>true,'engine'=>'PASS50 Data Engine','version'=>18,'threshold'=>$threshold,'profiles'=>$profiles,'eligibleProfiles'=>$eligible,'verifiedFacts'=>$verifiedFacts,'verifiedLinks'=>$verifiedLinks,'lastRun'=>$lastRun]);
