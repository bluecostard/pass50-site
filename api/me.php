<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('GET');
$u = auth_user();
json_response(['ok'=>true,'user'=>user_payload($u)]);
