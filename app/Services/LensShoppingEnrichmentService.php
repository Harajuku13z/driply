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
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function stripKind(array $match): array
    {
        unset($match['_kind']);

        return $match;
    }
}
