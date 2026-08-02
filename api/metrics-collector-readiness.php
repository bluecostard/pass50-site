<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/metrics-collector-readiness-core.php';

require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');
json_response(['ok'=>true,'readOnly'=>true,'publicStateWrites'=>0,'readiness'=>p50_mcr_status(db())]);
