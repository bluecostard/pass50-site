<?php
declare(strict_types=1);

require __DIR__.'/../api/metrics-schema-core.php';

function must(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$tz = p50_metrics_utc_timezone();
must($tz instanceof DateTimeZone, 'UTC timezone helper returns DateTimeZone');

$now = p50_metrics_now_utc();
must(abs(time() - $now->getTimestamp()) <= 2, 'now helper stays near current time');

$mysql = p50_metrics_parse_utc('2026-08-19 15:50:31');
must($mysql instanceof DateTimeImmutable, 'MySQL datetime parses');
must($mysql->format('Y-m-d H:i:s') === '2026-08-19 15:50:31', 'MySQL datetime keeps wall clock in UTC');

must(p50_metrics_parse_utc('0000-00-00 00:00:00') === null, 'Zero datetime is rejected');
must(p50_metrics_parse_utc('') === null, 'Empty datetime is rejected');

echo "metrics_timezone_helpers: ok\n";
