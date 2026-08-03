<?php
declare(strict_types=1);

return [
    'app' => [
        // Adresse publique finale, sans slash à la fin.
        'base_url' => 'https://votre-domaine.fr',
        'name' => 'PASS50',
        'session_days' => 30,
        'confirmation_hours' => 24,
        'reset_hours' => 1,
        // Laisser false en production.
        'show_confirmation_link_in_response' => false,
    ],
    'db' => [
        'host' => 'dbXXXXXXXX.hosting-data.io',
        'port' => 3306,
        'name' => 'dbsXXXXXXXX',
        'user' => 'dbuXXXXXXXX',
        'password' => 'CHANGEZ_CE_MOT_DE_PASSE',
        'charset' => 'utf8mb4',
    ],
    'brevo' => [
        'api_key' => 'xkeysib-VOTRE_CLE_API_BREVO',
        'sender_email' => 'contact@votre-domaine.fr',
        'sender_name' => 'PASS50',
    ],
    'google_oauth' => [
        // Ne jamais écrire ces secrets dans GitHub. Renseignez-les uniquement dans api/config.php sur IONOS
        // ou comme variables d’environnement du serveur.
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: 'https://www.pass50.store/api/youtube-oauth-callback.php',
        'token_encryption_key' => getenv('PASS50_TOKEN_ENCRYPTION_KEY') ?: '',
    ],
    'meta_oauth' => [
        'app_id' => getenv('META_APP_ID') ?: '',
        'app_secret' => getenv('META_APP_SECRET') ?: '',
        'configuration_id' => getenv('META_CONFIGURATION_ID') ?: '',
        'redirect_uri' => getenv('META_REDIRECT_URI') ?: 'https://www.pass50.store/api/meta-oauth-callback.php',
        'graph_version' => getenv('META_GRAPH_VERSION') ?: 'v22.0',
        'token_encryption_key' => getenv('PASS50_TOKEN_ENCRYPTION_KEY') ?: '',
    ],
    'tiktok_oauth' => [
        'client_key' => getenv('TIKTOK_CLIENT_KEY') ?: '',
        'client_secret' => getenv('TIKTOK_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('TIKTOK_REDIRECT_URI') ?: 'https://www.pass50.store/api/tiktok-oauth-callback.php',
        'environment' => getenv('TIKTOK_ENVIRONMENT') ?: 'sandbox',
        'token_encryption_key' => getenv('PASS50_TOKEN_ENCRYPTION_KEY') ?: '',
    ],
    'data_engine' => [
        'confidence_threshold' => 80,
        'cron_token' => '',
        'batch_size' => 8,
        'priority_wave_size' => 20,
        'live_batch_size' => 12,
        'live_refresh_seconds' => 50,
        'live_stale_minutes' => 45,
        'live_admin_token' => '',
    ],
    'metrics' => [
        // Secrets exclusivement dans api/config.php ou l’environnement du serveur.
        'PASS50_YOUTUBE_API_KEY' => getenv('PASS50_YOUTUBE_API_KEY') ?: '',
        'x_bearer_token' => getenv('PASS50_X_BEARER_TOKEN') ?: '',
        'tiktok_mode' => getenv('PASS50_TIKTOK_MODE') ?: 'none', // none, authorized_display, approved_research
        'tiktok_access_token' => getenv('PASS50_TIKTOK_ACCESS_TOKEN') ?: '',
        'tiktok_research_token' => getenv('PASS50_TIKTOK_RESEARCH_TOKEN') ?: '',
        'tiktok_research_approved' => filter_var(getenv('PASS50_TIKTOK_RESEARCH_APPROVED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        // Un jeton présent rend le collecteur statique opérationnel même si le drapeau est resté false.
        'instagram_enabled' => filter_var(getenv('PASS50_INSTAGRAM_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'instagram_mode' => getenv('PASS50_INSTAGRAM_MODE') ?: (getenv('PASS50_INSTAGRAM_DISCOVERY_ACCOUNT_ID') ? 'business_discovery' : 'professional_authorized'),
        'instagram_access_token' => getenv('PASS50_INSTAGRAM_ACCESS_TOKEN') ?: '',
        'instagram_account_id' => getenv('PASS50_INSTAGRAM_ACCOUNT_ID') ?: '',
        'instagram_discovery_account_id' => getenv('PASS50_INSTAGRAM_DISCOVERY_ACCOUNT_ID') ?: '',
        'facebook_enabled' => filter_var(getenv('PASS50_FACEBOOK_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'facebook_mode' => getenv('PASS50_FACEBOOK_MODE') ?: 'page_authorized',
        'facebook_access_token' => getenv('PASS50_FACEBOOK_ACCESS_TOKEN') ?: '',
        'facebook_page_id' => getenv('PASS50_FACEBOOK_PAGE_ID') ?: '',
        'meta_graph_version' => getenv('META_GRAPH_VERSION') ?: 'v22.0',
        'snapchat_enabled' => false,
        'snapchat_mode' => 'public_profile_api',
        'snapchat_access_token' => getenv('PASS50_SNAPCHAT_ACCESS_TOKEN') ?: '',
        'snapchat_stories_authorized' => false,
        'cron_secret' => getenv('PASS50_METRICS_CRON_SECRET') ?: '',
        // Collecte P0/P1/P2 + calcul MR-V1.0. Sans ceci le classement ne peut pas bouger.
        'orchestrator_enabled' => filter_var(getenv('PASS50_METRICS_ORCHESTRATOR_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        // Publier MR-V1.0 vers le classement public (app_state).
        'ranking_publication_enabled' => filter_var(getenv('PASS50_RANKING_PUBLICATION_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        // Publication automatique via cron HMAC (après garde-fous).
        'ranking_automatic_publication_enabled' => filter_var(getenv('PASS50_RANKING_AUTOMATIC_PUBLICATION_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        // 1er passage : autorise un fort turnover entrées/sorties pour débloquer un classement figé.
        'ranking_publication_bootstrap_allowed' => filter_var(getenv('PASS50_RANKING_BOOTSTRAP_ALLOWED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'p0_max_profiles' => 20,
        'p1_max_profiles' => 100,
        'p1_max_rank' => 70,
        'p2_max_profiles' => 500,
        'priority_profile_ids' => [],
        'p0_min_freshness_minutes' => 12,
        'p1_min_freshness_minutes' => 90,
        'p2_min_freshness_minutes' => 600,
        'worker_lock_timeout_minutes' => 10,
    ],
    'upload' => [
        'max_bytes' => 5 * 1024 * 1024,
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
    // Push iOS (Capacitor / APNs). Secrets uniquement dans config.php serveur.
    'push' => [
        'enabled' => filter_var(getenv('PASS50_PUSH_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'apns_key_id' => getenv('PASS50_APNS_KEY_ID') ?: '',
        'apns_team_id' => getenv('PASS50_APNS_TEAM_ID') ?: '',
        'apns_bundle_id' => getenv('PASS50_APNS_BUNDLE_ID') ?: 'store.pass50.app',
        // Chemin absolu vers AuthKey_XXXXX.p8 hors webroot.
        'apns_key_path' => getenv('PASS50_APNS_KEY_PATH') ?: '',
        'apns_production' => filter_var(getenv('PASS50_APNS_PRODUCTION') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'cron_secret' => getenv('PASS50_PUSH_CRON_SECRET') ?: '',
    ],
];
