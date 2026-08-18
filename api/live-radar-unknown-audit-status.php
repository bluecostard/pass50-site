<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/data-engine-core.php';
require __DIR__.'/live-radar-v4-core.php';
require_method('GET');

try { db()->exec("SET time_zone = '+00:00'"); } catch (Throwable) {}
p50_live_v4_ensure_schema();

$enabledRaw=p50_de_get_setting(P50_LIVE_V4_UNKNOWN_AUDIT_ENABLED_SETTING,true);
$enabled=!in_array($enabledRaw,[false,0,'0','false','off'],true);
$last=p50_de_get_setting(P50_LIVE_V4_UNKNOWN_AUDIT_LAST_SETTING,null);
$snapshot=p50_live_v4_unknown_audit_public_snapshot(is_array($last)?$last:[]);
$snapshot['enabled']=$enabled;
json_response($snapshot);
