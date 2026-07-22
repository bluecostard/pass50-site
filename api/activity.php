<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/data-engine-core.php';
require_method('GET');
p50_de_ensure_schema();
$profileId=trim((string)($_GET['profileId']??''));
$limit=max(1,min(50,(int)($_GET['limit']??20)));
if($profileId==='')json_response(['error'=>'Profil manquant.'],422);
json_response(['ok'=>true,'profileId'=>$profileId,'items'=>p50_de_activity_events($profileId,true,$limit),'threshold'=>p50_de_threshold()]);
