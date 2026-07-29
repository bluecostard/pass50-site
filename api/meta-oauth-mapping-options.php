<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require_method('GET');

$user=auth_user();
require_role($user,'owner','admin');
p50_de_sync_registry_from_state();

$profiles=array_map(static fn(array $row): array=>[
    'id'=>(string)$row['profile_id'],
    'name'=>(string)$row['public_name'],
    'handle'=>(string)($row['handle']??''),
    'category'=>(string)($row['category']??''),
    'eligible'=>!empty($row['eligible']),
],p50_de_registry_profiles(null,1000,0,false));

json_response(['ok'=>true,'profiles'=>$profiles]);
