<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Exceptions\InspirationAnalysisException;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline principal : orchestre les 9 etapes de la recherche visuelle.
 *
 * 1. Analyse image (GPT-4o Vision)
 * 2. Generation de requetes
 * 3. Recherche Google Lens (visuelle)
 * 4. Recherche Google Shopping (textuelle)
 * 5. Normalisation
 * 6. Deduplication
 * 7. Scoring
 * 8. Resume prix
 * 9. Retour structure
 */
class OutfitSearchPipeline
{
    public function __construct(
        private readonly ImageAnalysisService $imageAnalysis,
        private readonly QueryGenerationService $queryGeneration,
        private readonly GoogleLensService $lens,
        private readonly GoogleShoppingService $shopping,
        private readonly NormalizationService $normalization,
        private readonly DeduplicationService $deduplication,
        private readonly ScoringService $scoring,
    ) {}

    /**
     * Execute le pipeline complet.
     *
     * @return array{
     *     analysis: array<string, mixed>,
     *     scan_results: list<array<string, mixed>>,
     *     scan_price_summary: array<string, mixed>,
     *     item_type: string,
     *     brand: ?string,
     *     color: ?string,
     *     query: string,
     * }
     *
     * @throws InspirationAnalysisException
     */
    public function execute(string $imageUrl, string $base64Image, string $mimeType = 'image/jpeg'): array
    {
        $debug = (bool) config('vision.debug_mode', false);

        // ── Etape 1 : Analyse GPT-4o Vision ──
        $analysis = $this->imageAnalysis->analyze($base64Image, $mimeType);

        if ($debug) {
            Log::info('Pipeline: analyse GPT-4o', ['analysis' => $analysis]);
        }

        // ── Etape 2 : Generation des requetes ──
        $queries = $this->queryGeneration->generate($analysis);

        if ($queries === []) {
            throw new InspirationAnalysisException('Aucun vetement detecte dans l\'image.');
        }

        $primaryItem = $analysis['items'][0] ?? [];
        $itemType = (string) ($primaryItem['type'] ?? 'vetement');
        $brand    = isset($primaryItem['brand']) ? (string) $primaryItem['brand'] : null;
        $color    = isset($primaryItem['color']) ? (string) $primaryItem['color'] : null;

        // ── Etape 3 + 4 : Recherches paralleles (Lens + Shopping) ──
        $allRawResults = [];
        $mainQuery = '';

        foreach ($queries as $key => $querySet) {
            // Google Lens (visuel)
            $lensResults = $this->lens->searchByImage($imageUrl);
            $allRawResults = array_merge($allRawResults, $lensResults);

            // Google Shopping (texte) — requete primaire
            $mainQuery = $querySet['primary'];
            $shoppingResults = $this->shopping->searchByQuery($mainQuery);
            $allRawResults = array_merge($allRawResults, $shoppingResults);

            // Fallback si pas assez de resultats
            $minResults = (int) config('vision.limits.min_results_before_fallback', 3);
            if (count($allRawResults) < $minResults) {
                $fallbackResults = $this->shopping->searchByQuery($querySet['fallback']);
                $allRawResults = array_merge($allRawResults, $fallbackResults);

                if ($debug) {
                    Log::info('Pipeline: fallback active', ['query' => $querySet['fallback'], 'count' => count($fallbackResults)]);
                }
            }

            // On ne traite que le premier item detecte (le principal)
            break;
        }

        if ($debug) {
            Log::info('Pipeline: resultats bruts', ['count' => count($allRawResults)]);
        }

        // ── Etape 5 : Normalisation ──
        $normalized = $this->normalization->normalize($allRawResults);

        // ── Etape 6 : Deduplication ──
        $deduplicated = $this->deduplication->deduplicate($normalized);

        // ── Etape 7 : Scoring ──
        $scored = $this->scoring->score($deduplicated, $analysis);

        // Limiter aux max_products_per_item
        $maxProducts = (int) config('vision.limits.max_products_per_item', 5);
        $finalProducts = array_slice($scored, 0, $maxProducts);

        // ── Etape 8 : Resume prix ──
        $priceSummary = $this->buildPriceSummary($finalProducts);

        // ── Etape 9 : Structure de retour ──
        return [
            'analysis'           => $analysis,
            'scan_results'       => $finalProducts,
            'scan_price_summary' => $priceSummary,
            'item_type'          => $itemType,
            'brand'              => $brand,
            'color'              => $color,
            'query'              => $mainQuery,
        ];
    }

    /**
     * Construit le resume des prix.
     *
     * @param  list<array<string, mixed>>  $products
     * @return array{min_price: ?float, max_price: ?float, avg_price: ?float, currency: string, total_found: int}
     */
    private function buildPriceSummary(array $products): array
    {
        $prices = [];
        $currency = 'EUR';

        foreach ($products as $product) {
            if (($product['price'] ?? null) !== null && (float) $product['price'] > 0) {
                $prices[] = (float) $product['price'];
                $currency = (string) ($product['currency'] ?? 'EUR');
            }
        }

        if ($prices === []) {
            return [
                'min_price'   => null,
                'max_price'   => null,
                'avg_price'   => null,
                'currency'    => $currency,
                'total_found' => count($products),
            ];
        }

        return [
            'min_price'   => round(min($prices), 2),
            'max_price'   => round(max($prices), 2),
            'avg_price'   => round(array_sum($prices) / count($prices), 2),
            'currency'    => $currency,
            'total_found' => count($products),
        ];
    }
}
