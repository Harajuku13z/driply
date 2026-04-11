<?php

declare(strict_types=1);

namespace App\Services\Vision;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adaptateur SerpAPI pour Google Shopping (recherche par texte).
 */
class GoogleShoppingService
{
    /**
     * Recherche des produits sur Google Shopping via SerpAPI.
     *
     * @return list<array<string, mixed>>
     */
    public function searchByQuery(string $query): array
    {
        $apiKey  = (string) config('vision.serpapi.key');
        $baseUrl = (string) config('vision.serpapi.base_url', 'https://serpapi.com/search');
        $timeout = (int) config('vision.serpapi.timeout', 25);
        $retries = (int) config('vision.serpapi.retries', 2);
        $maxResults = (int) config('vision.limits.max_raw_results', 20);

        if ($apiKey === '' || trim($query) === '') {
            return [];
        }

        try {
            $response = Http::timeout($timeout)
                ->retry($retries, 500)
                ->get($baseUrl, [
                    'engine'  => 'google_shopping',
                    'q'       => $query,
                    'gl'      => 'fr',
                    'hl'      => 'fr',
                    'num'     => $maxResults,
                    'api_key' => $apiKey,
                ]);

            if ($response->failed()) {
                Log::error('GoogleShopping: erreur API', ['status' => $response->status(), 'query' => $query]);
                return [];
            }

            $data = $response->json();
            $shoppingResults = $data['shopping_results'] ?? [];

            $results = [];
            foreach (array_slice($shoppingResults, 0, $maxResults) as $item) {
                $results[] = [
                    'source'           => 'google_shopping',
                    'title'            => (string) ($item['title'] ?? ''),
                    'price'            => $this->extractPrice($item),
                    'currency'         => (string) ($item['extracted_price']['currency'] ?? 'EUR'),
                    'link'             => (string) ($item['link'] ?? $item['product_link'] ?? ''),
                    'thumbnail'        => (string) ($item['thumbnail'] ?? ''),
                    'source_name'      => (string) ($item['source'] ?? ''),
                    'in_stock'         => isset($item['delivery']) ? true : null,
                    'similarity_score' => null,
                    'raw'              => $item,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            Log::error('GoogleShopping: exception', ['error' => $e->getMessage(), 'query' => $query]);
            return [];
        }
    }

    private function extractPrice(array $item): ?float
    {
        if (isset($item['extracted_price'])) {
            return (float) $item['extracted_price'];
        }
        if (isset($item['price'])) {
            $raw = (string) $item['price'];
            $cleaned = (string) preg_replace('/[^0-9.,]/', '', $raw);
            $cleaned = str_replace(',', '.', $cleaned);
            $value = (float) $cleaned;
            return $value > 0 ? $value : null;
        }
        return null;
    }
}
