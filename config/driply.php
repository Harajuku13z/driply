<?php

declare(strict_types=1);

return [
    'serpapi' => [
        'key' => env('SERPAPI_KEY'),
        'base_url' => 'https://serpapi.com',
    ],
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
    ],
    'fastserver' => [
        'url' => rtrim((string) env('FASTSERVER_URL', ''), '/'),
        'key' => env('FASTSERVER_KEY'),
    ],
];
