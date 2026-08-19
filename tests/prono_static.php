<?php
declare(strict_types=1);

/**
 * Static smoke checks for Pronostics Phase A files.
 * Run: php tests/prono_static.php
 */

$root = dirname(__DIR__);
$required = [
    'api/prono-core.php',
    'api/prono-feed.php',
    'api/prono-vote.php',
    'api/prono-live.php',
    'api/prono-results.php',
    'api/prono-status-publish.php',
    'api/prono-status-like.php',
    'api/prono-statuses-feed.php',
    'api/prono-admin-save.php',
    'api/prono-admin-resolve.php',
    'api/prono-admin-list.php',
    'api/prono-admin-profiles.php',
    'api/admin-users.php',
    'api/prono-daily.php',
    'api/prono-daily-core.php',
    'pronostics.html',
    'pronostics.js',
    'admin-pronostics.html',
    'admin-membres.html',
];

foreach ($required as $rel) {
    $path = $root.'/'.$rel;
    if (!is_file($path)) {
        fwrite(STDERR, "MISSING $rel\n");
        exit(1);
    }
}

$core = file_get_contents($root.'/api/prono-core.php');
foreach (['p50_prono_ensure_schema', 'P50_PRONO_POINTS_STATUS_LIKE', 'p50_prono_statuses', '0.25', 'measure_at', 'p50_prono_lock_closed', 'odd_locked', 'p50_prono_payout', 'P50_PRONO_STARTING_BALANCE', 'P50_PRONO_BALANCE_FLOOR', 'stake_locked', 'P50_PRONO_VOTE_HOURS', '[6, 12, 24]', 'P50_PRONO_MAX_OPEN_PER_SUBJECT', 'p50_prono_subject_key', 'p50_prono_resolve_cover', 'coverPhoto', 'cover_image_url', 'p50_prono_compute_odds', 'p50_prono_assert_cover', 'P50_PRONO_DAILY_COUNT', 'p50_prono_profile_photo_any', 'P50_PRONO_THEMES', 'p50_prono_map_to_product_theme', 'p50_prono_slips', 'p50_prono_settle_slips', 'slip_id', 'P50_PRONO_LIVE_SOURCE', 'P50_PRONO_LIVE_PAYOUT_MULTIPLIER', 'p50_prono_live_sessions', 'p50_prono_is_live_question', 'live_session_id'] as $needle) {
    if (!str_contains($core, $needle)) {
        fwrite(STDERR, "CORE missing $needle\n");
        exit(1);
    }
}

$save = file_get_contents($root.'/api/prono-admin-save.php');
foreach (['voteDurationHours', 'measureAt', 'P50_PRONO_VOTE_HOURS', 'coverImageUrl', 'p50_prono_assert_cover', 'theme'] as $needle) {
    if (!str_contains($save, $needle)) {
        fwrite(STDERR, "ADMIN-SAVE missing $needle\n");
        exit(1);
    }
}

$js = file_get_contents($root.'/pronostics.js');
foreach (['prono-vote.php', 'prono-status-publish.php', 'prono-results.php', 'Sans argent réel', 'durationHours', 'measureAt', 'timingMeta', 'fmtOdd', 'odd', 'questionCoverSrc', 'card-media', 'themeSections', 'people_influenceurs'] as $needle) {
    if (!str_contains($js, $needle)) {
        fwrite(STDERR, "JS missing $needle\n");
        exit(1);
    }
}

$admin = file_get_contents($root.'/admin-pronostics.html');
foreach (['voteHours', 'measureAt', 'prono-admin-list.php', 'prono-admin-profiles.php', 'fiProfileList', 'loadFiProfiles', 'admin-membres.html', 'loadMembers', 'membersList', 'key|label|cote', 'stake', 'authGate', 'prono-daily.php', 'coverImageUrl', 'genDailyBtn', 'publishDailyBtn', 'people_influenceurs', 'data-theme-save', 'Les 3 thèmes', 'Prono50 live', 'liveActivateBtn', 'prono-live.php'] as $needle) {
    if (!str_contains($admin, $needle)) {
        fwrite(STDERR, "ADMIN-UI missing $needle\n");
        exit(1);
    }
}

$de = file_get_contents($root.'/data-engine-ui.js');
foreach (["['pronostics','Pronostics']", "['members','Membres']", 'deRenderPronosticsAdmin', 'deRenderMembersAdmin', 'deLoadHomeMembers', 'MEMBRES INSCRITS', 'admin-users.php', 'admin-pronostics.html'] as $needle) {
    if (!str_contains($de, $needle)) {
        fwrite(STDERR, "DATA-ENGINE missing $needle\n");
        exit(1);
    }
}

$fil = file_get_contents($root.'/mon-fil.js');
foreach (['prono_status', 'prono-statuses-feed.php', 'prono-status-like.php', 'pronoStoriesStrip', 'openPronoDiapo', 'memberAvatarHtml', 'diapoLegIndex', 'PASS50-FOLLOW-FEED-PAGE-V2.20'] as $needle) {
    if (!str_contains($fil, $needle)) {
        fwrite(STDERR, "MON-FIL missing $needle\n");
        exit(1);
    }
}

$filHtml = file_get_contents($root.'/mon-fil.html');
foreach (['pronoStoriesStrip', 'pronoDiapo', 'pronoDiapoShare', 'pronoDiapoOdd', 'mon-fil.js?v=2.20'] as $needle) {
    if (!str_contains($filHtml, $needle)) {
        fwrite(STDERR, "MON-FIL-HTML missing $needle\n");
        exit(1);
    }
}

$nav = file_get_contents($root.'/mobile-bottom-nav-v1.js');
if (!str_contains($nav, 'pronostics.html?v=83')) {
    fwrite(STDERR, "NAV missing versioned pronostics entry\n");
    exit(1);
}

$index = file_get_contents($root.'/index.html');
if (str_contains($index, 'id="pronostics"')) {
    fwrite(STDERR, "INDEX still has Pronostics banner section\n");
    exit(1);
}

$pronoHtml = file_get_contents($root.'/pronostics.html');
if (preg_match('/href="\.\/"[^>]*>\s*Classement\s*</u', $pronoHtml)) {
    fwrite(STDERR, "PRONO page still has Classement button\n");
    exit(1);
}
foreach (['qui-fait-quoi-v83', '--lime:#b7ff00', '--lime-soft:#71ff00', 'Statut prono', 'Qui fait quoi', 'prono-slip.php', 'Valider ma grille', 'Publier le statut prono', 'Publier le statut grille', 'statusStrip', 'prono-statuses-feed.php', 'mon-fil.html', 'z-index:260', 'slip-open', 'data-publish-slip', 'Jeux de pronostics sans argent', '100.000 pts', 'stakeInput', 'stakePlus', 'slipToggle', 'data-remove-qid', 'is-collapsed', 'pub-context', 'pub-context-label', 'renderPronoContext', 'coverLightbox', 'pub-cover-btn', 'openCoverLightbox'] as $needle) {
    if (!str_contains($pronoHtml, $needle)) {
        fwrite(STDERR, "PRONO-HTML missing $needle\n");
        exit(1);
    }
}

if (!str_contains($core, "'slipId'")) {
    fwrite(STDERR, "CORE missing myVote slipId\n");
    exit(1);
}

$slip = file_get_contents($root.'/api/prono-slip.php');
foreach (['combined_odd', 'legs', 'p50_prono_debit_stake', 'Maximum 8', 'P50_PRONO_LIVE_PAYOUT_MULTIPLIER', 'prono50_live'] as $needle) {
    if (!str_contains($slip, $needle)) {
        fwrite(STDERR, "SLIP missing $needle\n");
        exit(1);
    }
}

$resolve = file_get_contents($root.'/api/prono-admin-resolve.php');
foreach (['p50_prono_settle_slips', 'slip_id'] as $needle) {
    if (!str_contains($resolve, $needle)) {
        fwrite(STDERR, "RESOLVE missing $needle\n");
        exit(1);
    }
}

$feed = file_get_contents($root.'/api/prono-statuses-feed.php');
foreach (['legs_json', 'vote_slip_id', 'slip:'] as $needle) {
    if (!str_contains($feed, $needle)) {
        fwrite(STDERR, "STATUSES-FEED missing $needle\n");
        exit(1);
    }
}

$index = file_get_contents($root.'/index.html');
foreach (['adminMembersOpen', "adminOpen('members')"] as $needle) {
    if (!str_contains($index, $needle)) {
        fwrite(STDERR, "INDEX missing $needle\n");
        exit(1);
    }
}

$live = file_get_contents($root.'/api/prono-live.php');
foreach (["action === 'activate'", "action === 'deactivate'", 'saveQuestion', 'P50_PRONO_LIVE_SOURCE', 'livePayoutMultiplier'] as $needle) {
    if (!str_contains($live, $needle)) {
        fwrite(STDERR, "LIVE-API missing $needle\n");
        exit(1);
    }
}

echo "OK prono Phase A static checks\n";
