<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * Point d’entrée session app V1 — desktop + futurs clients store.
 * GET : contrats API + utilisateur si Bearer présent.
 */
const P50_APP_PLATFORM_CONTRACT = 'PASS50-APP-PLATFORM-V1';

$user = auth_user(false);
$session = $user ? user_payload($user) : null;

if ($session === null) {
    header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
} else {
    header('Cache-Control: private, no-store');
}

json_response([
    'ok' => true,
    'contract' => P50_APP_PLATFORM_CONTRACT,
    'authenticated' => $session !== null,
    'user' => $session,
    'endpoints' => [
        'ranking' => 'public-ranking.php',
        'feed' => 'public-feed.php',
        'live' => 'live-status.php?mode=status',
        'liveScan' => 'live-status.php?mode=quick',
        'pronoFeed' => 'prono-feed.php',
        'login' => 'login.php',
        'me' => 'me.php',
        'preferences' => 'preferences.php',
        'notifications' => 'notifications.php',
        'bootstrap' => 'app-bootstrap.php',
    ],
    'contracts' => [
        'platform' => P50_APP_PLATFORM_CONTRACT,
        'ranking' => 'PASS50-PUBLIC-RANKING-V1',
        'feed' => 'PASS50-PUBLIC-FEED-V1',
        'live' => 'PASS50-LIVE-STATUS-CACHE-V1',
    ],
    'guidance' => [
        'desktop' => 'Plateforme complète (classement, fil, prono, compte, admin).',
        'mobileApp' => 'Client web installable : ./app.html — même API ; live en mode=status uniquement.',
        'auth' => 'Authorization: Bearer <token> après login.php',
    ],
    'client' => [
        'webApp' => 'app.html',
        'contract' => 'PASS50-APP-CLIENT-V1',
        'manifest' => 'manifest.webmanifest',
    ],
    'generatedAt' => gmdate('c'),
]);
