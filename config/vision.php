<?php

declare(strict_types=1);

return [

    'debug_mode' => env('VISION_DEBUG', false),

    /**
     * Capture / POST scan :
     * - legacy (défaut) : SerpApi **Google Lens** sur l’URL de la photo, puis **Google Shopping** (plusieurs requêtes
     *   dérivées des titres Lens) — **sans GPT / OpenAI**, **sans Node**.
     * - serpapi : package Node @driply/serpapi-outfit-search (nécessite `node` + paquet npm).
     */
    'scan_driver' => env('VISION_SCAN_DRIVER', 'legacy'),

    'limits' => [
        'max_items_per_scan' => 3,
        /** Plafond d’offres renvoyées au client après scoring (le bouton « Voir plus » affiche le lot suivant côté app). */
        'max_products_per_item' => (int) env('VISION_MAX_PRODUCTS_PER_ITEM', 45),
        'max_raw_results' => (int) env('VISION_MAX_RAW_RESULTS', 30),
        'min_results_before_fallback' => 3,
        /** Nombre de requêtes Shopping distinctes générées à partir des titres Lens (plus = plus de résultats, plus d’appels SerpApi). */
        'max_shopping_queries_from_lens' => (int) env('VISION_MAX_SHOPPING_QUERIES', 5),
        /** Plafond après dédoublonnage (avant scoring). */
        'max_candidates_after_dedup' => (int) env('VISION_MAX_CANDIDATES_AFTER_DEDUP', 40),
        /** Minimum d’offres renvoyées pour valider un scan (sinon erreur 422). */
        'min_scan_results' => (int) env('VISION_MIN_SCAN_RESULTS', 10),
        /**
         * Grand côté cible pour les URLs d’image Google (lh3.googleusercontent, gstatic =w/-h/, =s/).
         * Les miniatures SerpAPI petites sont « upscalées » dans l’URL quand le CDN le permet.
         */
        'max_google_image_edge' => (int) env('VISION_MAX_GOOGLE_IMAGE_EDGE', 1600),
    ],

    'weights' => [
        'has_price' => 20,
        'color_match' => 20,
        'category_match' => 15,
        'has_image' => 10,
        'has_brand' => 10,
        'has_merchant' => 10,
        'in_stock' => 8,
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
        'noir' => ['black', 'noir', 'dark', 'ebony', 'onyx'],
        'blanc' => ['white', 'blanc', 'ivory', 'cream', 'off-white'],
        'bleu' => ['blue', 'bleu', 'navy', 'indigo', 'cobalt', 'denim'],
        'rouge' => ['red', 'rouge', 'scarlet', 'crimson', 'bordeaux'],
        'vert' => ['green', 'vert', 'olive', 'khaki', 'emerald'],
        'gris' => ['grey', 'gray', 'gris', 'charcoal', 'silver'],
        'beige' => ['beige', 'sand', 'cream', 'nude', 'camel', 'tan'],
        'marron' => ['brown', 'marron', 'camel', 'chocolate', 'cognac'],
        'rose' => ['pink', 'rose', 'blush', 'fuchsia', 'coral'],
        'jaune' => ['yellow', 'jaune', 'mustard', 'gold', 'amber'],
        'orange' => ['orange', 'rust', 'terracotta', 'burnt'],
        'violet' => ['purple', 'violet', 'lavender', 'lilac', 'mauve'],
    ],

    'serpapi' => [
        'key' => env('SERPAPI_KEY'),
        'base_url' => 'https://serpapi.com/search',
        'timeout' => (int) env('SERPAPI_TIMEOUT', 25),
        'retries' => (int) env('SERPAPI_RETRIES', 2),
        /** Binaire Node (PATH ou chemin absolu) pour bin/serpapi-outfit-search.mjs */
        'node_binary' => env('NODE_BINARY', 'node'),
        /** Script pont vers le package npm */
        'node_script' => env('SERPAPI_NODE_SCRIPT', base_path('bin/serpapi-outfit-search.mjs')),
        'language' => env('SERPAPI_HL', 'fr'),
        'country' => env('SERPAPI_GL', 'fr'),
        'google_domain' => env('SERPAPI_GOOGLE_DOMAIN', 'google.com'),
        'lens_type' => env('SERPAPI_LENS_TYPE', 'visual_matches'),
        'max_calls_per_search' => (int) env('SERPAPI_MAX_CALLS_PER_SEARCH', 28),
        'process_timeout_seconds' => (float) env('SERPAPI_PROCESS_TIMEOUT', 120),
        /** true = réponses mock (tests sans quota SerpApi) */
        'use_mocks' => env('SERPAPI_USE_MOCKS', false),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'timeout' => 30,
        'temperature' => 0,
    ],
];
