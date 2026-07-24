<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('GET');
p50_de_ensure_schema();
$profiles=(int)db()->query('SELECT COUNT(*) FROM p50_profile_registry')->fetchColumn();
$verifiedFacts=(int)db()->query("SELECT COUNT(*) FROM p50_facts WHERE status='verified' AND confidence>=90")->fetchColumn();
$verifiedLinks=(int)db()->query("SELECT COUNT(*) FROM p50_social_links WHERE status='verified' AND confidence>=90")->fetchColumn();
$lastRun=db()->query('SELECT status,collector,started_at,finished_at FROM p50_collection_runs ORDER BY started_at DESC LIMIT 1')->fetch()?:null;
json_response(['ok'=>true,'engine'=>'PASS50 Data Engine','threshold'=>p50_de_threshold(),'profiles'=>$profiles,'verifiedFacts'=>$verifiedFacts,'verifiedLinks'=>$verifiedLinks,'lastRun'=>$lastRun]);
