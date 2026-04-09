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
    ],
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
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
