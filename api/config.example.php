<?php
declare(strict_types=1);

return [
    'app' => [
        'base_url' => 'https://votre-domaine.fr',
        'name' => 'PASS50',
        'session_days' => 30,
        'confirmation_hours' => 24,
        'reset_hours' => 1,
        'show_confirmation_link_in_response' => false,
    ],
    'db' => [
        'host' => 'dbXXXXXXXX.hosting-data.io','port' => 3306,'name' => 'dbsXXXXXXXX','user' => 'dbuXXXXXXXX','password' => 'CHANGEZ_CE_MOT_DE_PASSE','charset' => 'utf8mb4',
    ],
    'brevo' => [
        'api_key' => 'xkeysib-VOTRE_CLE_API_BREVO','sender_email' => 'contact@votre-domaine.fr','sender_name' => 'PASS50',
    ],
    'google_oauth' => [
        // Secrets uniquement dans api/config.php sur IONOS ou dans les variables d’environnement.
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: 'https://www.pass50.store/api/youtube-oauth-callback.php',
        'token_encryption_key' => getenv('PASS50_TOKEN_ENCRYPTION_KEY') ?: '',
    ],
    'meta_oauth' => [
        // Créer une application Business dans Meta for Developers puis ajouter Facebook Login for Business.
        // Ne jamais écrire App Secret ou jetons dans GitHub.
        'app_id' => getenv('META_APP_ID') ?: '',
        'app_secret' => getenv('META_APP_SECRET') ?: '',
        'redirect_uri' => getenv('META_REDIRECT_URI') ?: 'https://www.pass50.store/api/meta-oauth-callback.php',
        // Utiliser la version Graph API affichée dans le tableau de bord de l’application Meta.
        'graph_version' => getenv('META_GRAPH_VERSION') ?: '',
        // Peut réutiliser la même clé de 32 octets que YouTube.
        'token_encryption_key' => getenv('PASS50_TOKEN_ENCRYPTION_KEY') ?: '',
    ],
    'data_engine' => [
        'confidence_threshold' => 90,'cron_token' => '','batch_size' => 5,'priority_wave_size' => 16,'live_batch_size' => 6,'live_refresh_seconds' => 50,'live_stale_minutes' => 45,'live_admin_token' => '',
    ],
    'metrics' => [
        'PASS50_YOUTUBE_API_KEY' => '',
        'x_bearer_token' => getenv('PASS50_X_BEARER_TOKEN') ?: '',
        'tiktok_mode' => 'none','tiktok_access_token' => getenv('PASS50_TIKTOK_ACCESS_TOKEN') ?: '','tiktok_research_token' => getenv('PASS50_TIKTOK_RESEARCH_TOKEN') ?: '','tiktok_research_approved' => false,
        'instagram_enabled' => false,'instagram_mode' => 'professional_authorized','instagram_access_token' => getenv('PASS50_INSTAGRAM_ACCESS_TOKEN') ?: '','instagram_account_id' => getenv('PASS50_INSTAGRAM_ACCOUNT_ID') ?: '','instagram_discovery_account_id' => getenv('PASS50_INSTAGRAM_DISCOVERY_ACCOUNT_ID') ?: '',
        'facebook_enabled' => false,'facebook_mode' => 'page_authorized','facebook_access_token' => getenv('PASS50_FACEBOOK_ACCESS_TOKEN') ?: '','facebook_page_id' => getenv('PASS50_FACEBOOK_PAGE_ID') ?: '',
        'snapchat_enabled' => false,'snapchat_mode' => 'public_profile_api','snapchat_access_token' => getenv('PASS50_SNAPCHAT_ACCESS_TOKEN') ?: '','snapchat_stories_authorized' => false,
        'cron_secret' => getenv('PASS50_METRICS_CRON_SECRET') ?: '','orchestrator_enabled' => false,'p0_max_profiles' => 20,'p1_max_profiles' => 100,'p1_max_rank' => 70,'p2_max_profiles' => 500,'priority_profile_ids' => [],'p0_min_freshness_minutes' => 12,'p1_min_freshness_minutes' => 90,'p2_min_freshness_minutes' => 600,'worker_lock_timeout_minutes' => 10,
    ],
    'upload' => [
        'max_bytes' => 5 * 1024 * 1024,'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];
