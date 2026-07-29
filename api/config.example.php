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
        // Clé de 32 octets encodée en base64, utilisée pour chiffrer les jetons OAuth en base.
        'token_encryption_key' => getenv('PASS50_TOKEN_ENCRYPTION_KEY') ?: '',
    ],
    'meta_oauth' => [
        // Application Business Meta. Ne jamais écrire App Secret ou jetons dans GitHub.
        'app_id' => getenv('META_APP_ID') ?: '',
        'app_secret' => getenv('META_APP_SECRET') ?: '',
        'redirect_uri' => getenv('META_REDIRECT_URI') ?: 'https://www.pass50.store/api/meta-oauth-callback.php',
        // Utiliser la version Graph API affichée dans le tableau de bord de l’application Meta.
        'graph_version' => getenv('META_GRAPH_VERSION') ?: '',
        // Peut réutiliser la même clé de chiffrement de 32 octets que YouTube.
        'token_encryption_key' => getenv('PASS50_TOKEN_ENCRYPTION_KEY') ?: '',
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
        // Orchestrateur PR5 : activation explicite après configuration d'un secret d'au moins 32 caractères.
        'cron_secret' => getenv('PASS50_METRICS_CRON_SECRET') ?: '',
        'orchestrator_enabled' => false,
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
];
