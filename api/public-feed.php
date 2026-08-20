<?php
declare(strict_types=1);

/**
 * Alias stable du fil public pour clients app (desktop + mobile).
 * Délègue à content-feed.php (même payload + contract injecté côté feed).
 */
if (!defined('P50_PUBLIC_FEED_CONTRACT')) {
    define('P50_PUBLIC_FEED_CONTRACT', 'PASS50-PUBLIC-FEED-V1');
}
require __DIR__ . '/content-feed.php';
