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
    | Import médias réseaux : backend custom POST /media/fetch, ou FastSaverAPI
    | (FASTSERVER_URL hôte fastsaverapi.com → GET /get-info + token en query).
    */
    'fastserver' => [
        'url' => rtrim((string) env('FASTSERVER_URL', ''), '/'),
        'key' => env('FASTSERVER_KEY'),
    ],
];
