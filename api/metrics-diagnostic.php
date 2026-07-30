<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/metrics-schema-core.php';
require __DIR__.'/metrics-collectors-core.php';
require __DIR__.'/metrics-orchestrator-core.php';
require __DIR__.'/metrics-observability-core.php';
require __DIR__.'/metrics-control-center-core.php';
require __DIR__.'/metrics-ranking-readiness-core.php';

require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');

// Lecture seule absolue : aucun ensure_schema, recalcul ou pipeline de publication.
$pdo=db();
$diagnostic=p50_obs_diagnostic($pdo,p50_de_threshold());
$diagnostic['controlCenter']=p50mcc_status($pdo,p50_de_threshold());
$diagnostic['rankingReadiness']=p50_mrr_readiness($pdo,new DateTimeImmutable('now',new DateTimeZone('UTC')));
json_response($diagnostic);
