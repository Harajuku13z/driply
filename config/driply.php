<?php

declare(strict_types=1);

return [
    /*
    | Si renseigné, la page /api-verif exige ?token=VOTRE_TOKEN
    */
    'api_verif_token' => env('API_VERIF_TOKEN'),

    'serpapi' => [
        'key' => env('SERPAPI_KEY'),
        'base_url' => 'https://serpapi.com',
        'no_cache' => filter_var(env('SERPAPI_NO_CACHE', true), FILTER_VALIDATE_BOOL),
    ],
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],

    /*
    | Analyse Lens : nombre de correspondances visuelles, puis une recherche Google Shopping
    | (SerpAPI) par titre pour récupérer prix + miniatures. gl/hl = pays / langue Shopping.
    */
    'lens' => [
        'top_visual_matches' => max(1, min(8, (int) env('DRIPLY_LENS_TOP_MATCHES', 4))),
        'minimum_rows' => max(1, min(8, (int) env('DRIPLY_LENS_MIN_ROWS', 3))),
        'shopping_offers_per_match' => max(1, min(20, (int) env('DRIPLY_LENS_SHOPPING_OFFERS', 6))),
        'shopping_gl' => env('DRIPLY_SERPAPI_SHOPPING_GL', 'fr'),
        'shopping_hl' => env('DRIPLY_SERPAPI_SHOPPING_HL', 'fr'),
        /*
        | URL publique HTTPS de base vers /storage (sans slash final). Si défini, utilisé en priorité
        | sur la route /driply-public/ (utile si ton serveur sert correctement /storage via symlink).
        */
        'public_storage_base_url' => env('DRIPLY_LENS_PUBLIC_STORAGE_BASE_URL', ''),
        /*
        | Si true : URL générée = APP_URL + /driply-public/lens/… (contourne symlink storage manquant).
        | Mettre false uniquement si `php artisan storage:link` fonctionne et /storage/ est public.
        */
        'use_public_file_route' => filter_var(env('DRIPLY_LENS_USE_PUBLIC_FILE_ROUTE', true), FILTER_VALIDATE_BOOL),
        /*
        | Si shopping_results (Lens) a moins de N entrées, appel GPT-4o vision pour affiner la requête Shopping.
        */
        'vision_shopping_threshold' => max(1, min(10, (int) env('DRIPLY_LENS_VISION_SHOPPING_THRESHOLD', 3))),
        'shopping_fetch_limit' => max(5, min(40, (int) env('DRIPLY_LENS_SHOPPING_FETCH', 20))),
    ],
    /*
    | App iOS (Hostinger legacy) : upload.php + api/sync_media.php avec en-tête X-Driply-Key.
    | Laisser vide en local pour autoriser l’upload sans clé ; définir en production.
    */
    'legacy_api_key' => env('DRIPLY_LEGACY_API_KEY', ''),

    /*
    | Page web « e-mail vérifié » : bouton qui ouvre l’app iOS (CFBundleURLSchemes = driply).
    */
    'ios_open_app_url' => env('DRIPLY_IOS_OPEN_APP_URL', 'driply://email-verified'),

    /*
    | Après réinitialisation du mot de passe sur le site (formulaire web).
    */
    'ios_open_app_after_password_reset_url' => env('DRIPLY_IOS_OPEN_APP_AFTER_PASSWORD_RESET', 'driply://'),

    /*
    | Import médias réseaux : backend custom POST /media/fetch, ou FastSaverAPI
    | (FASTSERVER_URL hôte fastsaverapi.com → GET /get-info + token en query).
    */
    'fastserver' => [
        'url' => rtrim((string) env('FASTSERVER_URL', ''), '/'),
        'key' => env('FASTSERVER_KEY'),
    ],
];
