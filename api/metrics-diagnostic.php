<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/metrics-schema-core.php';
require __DIR__.'/metrics-collectors-core.php';
require __DIR__.'/metrics-observability-core.php';

require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');

// Lecture seule absolue : aucun ensure_schema, recalcul ou pipeline de publication.
json_response(p50_obs_diagnostic(db(),p50_de_threshold()));
