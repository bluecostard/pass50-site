<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_method('POST');
$token = bearer_token();
if ($token) db()->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256',$token)]);
json_response(['ok'=>true]);
