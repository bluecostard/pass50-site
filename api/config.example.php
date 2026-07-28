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
    'data_engine' => [
        // PASS50 publie uniquement les données à 90 % ou plus.
        'confidence_threshold' => 90,
        // Facultatif : ajoutez une valeur longue et aléatoire avant d'activer un cron externe.
        'cron_token' => '',
        'batch_size' => 5,
        // V22 : lancer data-cron.php?action=priority16 une fois la nuit, puis cycle toutes les 15 minutes.
        'priority_wave_size' => 16,
        // Radar LIVE : nombre de chaînes YouTube contrôlées à chaque passage.
        'live_batch_size' => 6,
        // Intervalle minimum entre deux passages du radar public.
        'live_refresh_seconds' => 50,
        // Sécurité : un live automatique non revu depuis ce délai est retiré.
        'live_stale_minutes' => 45,
        // Jeton du contrôle LIVE, uniquement dans api/config.php sur le serveur.
        'live_admin_token' => '',
    ],
    'metrics' => [
        // À renseigner uniquement dans api/config.php sur le serveur IONOS.
        'PASS50_YOUTUBE_API_KEY' => '',
        // Facultatif : réservé au connecteur officiel X déjà présent dans metrics-core.php.
        'x_bearer_token' => getenv('PASS50_X_BEARER_TOKEN') ?: '',
        // Collecteurs sociaux canoniques V1 : secrets exclusivement côté serveur.
        'tiktok_mode' => 'none', // none, authorized_display ou approved_research
        'tiktok_access_token' => getenv('PASS50_TIKTOK_ACCESS_TOKEN') ?: '',
        'tiktok_research_token' => getenv('PASS50_TIKTOK_RESEARCH_TOKEN') ?: '',
        'tiktok_research_approved' => false,
        'instagram_enabled' => false,
        'instagram_mode' => 'professional_authorized',
        'instagram_access_token' => getenv('PASS50_INSTAGRAM_ACCESS_TOKEN') ?: '',
        'instagram_account_id' => getenv('PASS50_INSTAGRAM_ACCOUNT_ID') ?: '',
        'instagram_discovery_account_id' => getenv('PASS50_INSTAGRAM_DISCOVERY_ACCOUNT_ID') ?: '',
        'facebook_enabled' => false,
        'facebook_mode' => 'page_authorized',
        'facebook_access_token' => getenv('PASS50_FACEBOOK_ACCESS_TOKEN') ?: '',
        'facebook_page_id' => getenv('PASS50_FACEBOOK_PAGE_ID') ?: '',
        'snapchat_enabled' => false,
        'snapchat_mode' => 'public_profile_api',
        'snapchat_access_token' => getenv('PASS50_SNAPCHAT_ACCESS_TOKEN') ?: '',
        'snapchat_stories_authorized' => false,
    ],
    'upload' => [
        'max_bytes' => 5 * 1024 * 1024,
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];
