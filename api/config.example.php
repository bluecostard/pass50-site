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
        // Radar LIVE : nombre de chaînes YouTube contrôlées à chaque passage.
        'live_batch_size' => 6,
        // Intervalle minimum entre deux passages du radar public.
        'live_refresh_seconds' => 50,
        // Sécurité : un live automatique non revu depuis ce délai est retiré.
        'live_stale_minutes' => 45,
    ],
    'upload' => [
        'max_bytes' => 5 * 1024 * 1024,
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];
