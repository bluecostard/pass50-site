<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/intelligence-core.php';
require_method('GET');
$user=auth_user();
require_role($user,'owner','admin');
json_response(['ok'=>true]+p50_intelligence_dashboard());
