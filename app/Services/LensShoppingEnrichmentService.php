<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pour chaque correspondance Lens (titre), interroge Google Shopping (SerpAPI) afin d’obtenir
 * des offres avec prix et miniatures, comme décrit dans la doc SerpAPI Shopping / Lens.
 */
class LensShoppingEnrichmentService
{
    public function __construct(
        private readonly SerpApiService $serpApi,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $lensMatches  Résultats normalisés (GoogleLensService)
     * @return array<int, array<string, mixed>>
     */
    public function enrich(array $lensMatches): array
    {
        $perQuery = (int) config('driply.lens.shopping_offers_per_match', 6);
        $gl = (string) config('driply.lens.shopping_gl', 'fr');
        $hl = (string) config('driply.lens.shopping_hl', 'fr');

        $out = [];
        foreach ($lensMatches as $match) {
            $row = $this->stripKind($match);
            $query = Str::limit(trim((string) ($row['title'] ?? '')), 220, '');
            $offers = [];
            if ($query !== '') {
                try {
                    $offers = $this->serpApi->googleShoppingSearch($query, $perQuery, $gl, $hl);
                } catch (Throwable $e) {
                    Log::warning('driply.lens_shopping_enrichment_failed', [
                        'message' => $e->getMessage(),
                        'query' => $query,
                    ]);
                }
            }
            $row['shopping_offers'] = $offers;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Si peu de lignes ou aucune miniature / offre Shopping, complète via une recherche globale
     * à partir du JSON Lens brut (knowledge_graph, etc.).
     *
     * @param  array<int, array<string, mixed>>  $enriched
     * @param  array<string, mixed>  $rawLensResponse
     * @return array<int, array<string, mixed>>
     */
    public function ensureMinimumDepth(array $enriched, array $rawLensResponse, int $minRows): array
    {
        $minRows = max(1, $minRows);
        $needsMoreRows = count($enriched) < $minRows;
        $needsOffers = true;
        foreach ($enriched as $row) {
            if (! empty($row['shopping_offers'])) {
                $needsOffers = false;
                break;
            }
        }
        $needsThumb = true;
        foreach ($enriched as $row) {
            if (($row['thumbnail_url'] ?? '') !== '' || ($row['image_url'] ?? '') !== '') {
                $needsThumb = false;
                break;
            }
        }

        if (! $needsMoreRows && ! $needsOffers && ! $needsThumb) {
            return $enriched;
        }

        $globalQuery = LensSerpRawQuery::bestShoppingQuery($rawLensResponse);
        if ($globalQuery === null || $globalQuery === '') {
            return $enriched;
        }

        $perQuery = max((int) config('driply.lens.shopping_offers_per_match', 6), $minRows * 3);
        $gl = (string) config('driply.lens.shopping_gl', 'fr');
        $hl = (string) config('driply.lens.shopping_hl', 'fr');

        try {
            $bulk = $this->serpApi->googleShoppingSearch($globalQuery, min(40, $perQuery), $gl, $hl);
        } catch (Throwable $e) {
            Log::warning('driply.lens_shopping_global_fallback_failed', [
                'message' => $e->getMessage(),
                'query' => $globalQuery,
            ]);

            return $enriched;
        }

        if ($bulk === []) {
            return $enriched;
        }

        // Fusionner les offres dans les lignes déjà là si elles sont vides
        $i = 0;
        foreach ($enriched as $idx => $row) {
            if (! empty($row['shopping_offers'])) {
                continue;
            }
            $slice = array_slice($bulk, $i, (int) config('driply.lens.shopping_offers_per_match', 6));
            $i += count($slice);
            $enriched[$idx]['shopping_offers'] = $slice;
        }

        // Construire des lignes synthétiques à partir du Shopping si toujours trop peu de lignes
        if (count($enriched) < $minRows) {
            $usedLinks = [];
            foreach ($enriched as $r) {
                $usedLinks[(string) ($r['product_url'] ?? '')] = true;
            }
            foreach ($bulk as $offer) {
                if (count($enriched) >= $minRows) {
                    break;
                }
                $link = (string) ($offer['link'] ?? '');
                if ($link !== '' && isset($usedLinks[$link])) {
                    continue;
                }
                if ($link !== '') {
                    $usedLinks[$link] = true;
                }
                $enriched[] = $this->syntheticRowFromOffer($offer);
            }
        }

        return array_values($enriched);
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return array<string, mixed>
     */
    private function syntheticRowFromOffer(array $offer): array
    {
        $thumb = (string) ($offer['thumbnail_url'] ?? '');
        $price = $offer['extracted_price'] ?? null;
        $priceFound = is_numeric($price) ? (string) ($price + 0) : (isset($offer['price']) && is_scalar($offer['price']) ? (string) $offer['price'] : null);

        return [
            'title' => (string) ($offer['title'] ?? ''),
            'source' => (string) ($offer['source'] ?? ''),
            'thumbnail_url' => $thumb,
            'image_url' => $thumb,
            'product_url' => (string) ($offer['link'] ?? ''),
            'price_found' => $priceFound,
            'currency_found' => isset($offer['currency']) && is_scalar($offer['currency']) ? (string) $offer['currency'] : null,
            'shopping_offers' => [$offer],
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function stripKind(array $match): array
    {
        unset($match['_kind']);

        return $match;
    }
}
