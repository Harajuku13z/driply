<?php

declare(strict_types=1);

return [

    'debug_mode' => env('VISION_DEBUG', false),

    'limits' => [
        'max_items_per_scan'          => 3,
        'max_products_per_item'       => 5,
        'max_raw_results'             => 20,
        'min_results_before_fallback' => 3,
    ],

    'weights' => [
        'has_price'        => 20,
        'color_match'      => 20,
        'category_match'   => 15,
        'has_image'        => 10,
        'has_brand'        => 10,
        'has_merchant'     => 10,
        'in_stock'         => 8,
        'similarity_score' => 7,
    ],

    'rank_labels' => [
        0 => 'Meilleur prix',
        1 => 'Bon plan',
        2 => 'Prix moyen',
        3 => 'Haut de gamme',
        4 => 'Premium',
    ],

    'color_map' => [
        'noir'   => ['black', 'noir', 'dark', 'ebony', 'onyx'],
        'blanc'  => ['white', 'blanc', 'ivory', 'cream', 'off-white'],
        'bleu'   => ['blue', 'bleu', 'navy', 'indigo', 'cobalt', 'denim'],
        'rouge'  => ['red', 'rouge', 'scarlet', 'crimson', 'bordeaux'],
        'vert'   => ['green', 'vert', 'olive', 'khaki', 'emerald'],
        'gris'   => ['grey', 'gray', 'gris', 'charcoal', 'silver'],
        'beige'  => ['beige', 'sand', 'cream', 'nude', 'camel', 'tan'],
        'marron' => ['brown', 'marron', 'camel', 'chocolate', 'cognac'],
        'rose'   => ['pink', 'rose', 'blush', 'fuchsia', 'coral'],
        'jaune'  => ['yellow', 'jaune', 'mustard', 'gold', 'amber'],
        'orange' => ['orange', 'rust', 'terracotta', 'burnt'],
        'violet' => ['purple', 'violet', 'lavender', 'lilac', 'mauve'],
    ],

    'serpapi' => [
        'key'      => env('SERPAPI_KEY'),
        'base_url' => 'https://serpapi.com/search',
        'timeout'  => 25,
        'retries'  => 2,
    ],

    'openai' => [
        'key'         => env('OPENAI_API_KEY'),
        'model'       => env('OPENAI_MODEL', 'gpt-4o'),
        'timeout'     => 30,
        'temperature' => 0,
    ],
];
