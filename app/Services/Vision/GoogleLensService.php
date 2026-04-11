<?php

declare(strict_types=1);

namespace App\Services\Vision;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adaptateur SerpAPI pour Google Lens (recherche visuelle par image).
 */
class GoogleLensService
{
    /**
     * Recherche des produits visuellement similaires via Google Lens.
     *
     * @return list<array<string, mixed>>
     */
    public function searchByImage(string $imageUrl): array
    {
        $apiKey  = (string) config('vision.serpapi.key');
        $baseUrl = (string) config('vision.serpapi.base_url', 'https://serpapi.com/search');
        $timeout = (int) config('vision.serpapi.timeout', 25);
        $retries = (int) config('vision.serpapi.retries', 2);
        $maxResults = (int) config('vision.limits.max_raw_results', 20);

        if ($apiKey === '') {
            Log::warning('GoogleLens: cle SerpAPI manquante');
            return [];
        }

        try {
            $response = Http::timeout($timeout)
                ->retry($retries, 500)
                ->get($baseUrl, [
                    'engine'  => 'google_lens',
                    'url'     => $imageUrl,
                    'api_key' => $apiKey,
                ]);

            if ($response->failed()) {
                Log::error('GoogleLens: erreur API', ['status' => $response->status()]);
                return [];
            }

            $data = $response->json();

            $visualMatches = $data['visual_matches'] ?? [];

            if ($visualMatches === [] && ! empty($data['error'])) {
                Log::warning('GoogleLens: reponse SerpAPI sans visual_matches', [
                    'error' => $data['error'],
                    'image_url' => $imageUrl,
                ]);
            }

            $results = [];
            foreach (array_slice($visualMatches, 0, $maxResults) as $match) {
                $results[] = [
                    'source'           => 'google_lens',
                    'title'            => (string) ($match['title'] ?? ''),
                    'price'            => $this->extractPrice($match),
                    'currency'         => $this->extractCurrency($match),
                    'link'             => (string) ($match['link'] ?? ''),
                    'thumbnail'        => (string) ($match['thumbnail'] ?? ''),
                    'source_name'      => (string) ($match['source'] ?? ''),
                    'in_stock'         => null,
                    'similarity_score' => isset($match['position']) ? max(0, 100 - (int) $match['position'] * 5) : null,
                    'raw'              => $match,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            Log::error('GoogleLens: exception', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function extractPrice(array $match): ?float
    {
        if (isset($match['price']['extracted_value'])) {
            return (float) $match['price']['extracted_value'];
        }
        if (isset($match['price']['value'])) {
            $raw = (string) $match['price']['value'];
            $cleaned = (string) preg_replace('/[^0-9.,]/', '', $raw);
            $cleaned = str_replace(',', '.', $cleaned);
            $value = (float) $cleaned;
            return $value > 0 ? $value : null;
        }
        return null;
    }

    private function extractCurrency(array $match): string
    {
        return (string) ($match['price']['currency'] ?? 'EUR');
    }
}
