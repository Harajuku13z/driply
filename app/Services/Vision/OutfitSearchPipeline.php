<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Exceptions\InspirationAnalysisException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pipeline principal : selon `vision.scan_driver`, soit le package Node SerpApi (capture),
 * soit l’ancienne chaîne GPT-4o Vision + services PHP.
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
        private readonly SerpApiOutfitSearchRunner $serpApiRunner,
    ) {}

    /**
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
        $driver = (string) config('vision.scan_driver', 'serpapi');

        if ($driver === 'serpapi') {
            return $this->executeSerpApiNode($imageUrl);
        }

        return $this->executeLegacyPhp($imageUrl, $base64Image, $mimeType);
    }

    /**
     * @throws InspirationAnalysisException
     */
    private function executeSerpApiNode(string $imageUrl): array
    {
        $debug = (bool) config('vision.debug_mode', false);
        $raw = $this->serpApiRunner->run($imageUrl);

        if ($debug) {
            Log::info('Pipeline SerpApi Node: reponse brute (resume)', [
                'detected' => $raw['inputSummary'] ?? null,
                'blocks' => count($raw['resultsByItem'] ?? []),
            ]);
        }

        /** @var list<string> $detectedItems */
        $detectedItems = array_values(array_filter(
            array_map('strval', $raw['inputSummary']['detectedItems'] ?? []),
            fn (string $s) => $s !== ''
        ));
        if ($detectedItems === []) {
            $detectedItems = ['vetement'];
        }

        $brandHints = $raw['inputSummary']['brandHints'] ?? [];
        $colors = $raw['inputSummary']['colors'] ?? [];
        $brand = isset($brandHints[0]) ? (string) $brandHints[0] : null;
        $color = isset($colors[0]) ? (string) $colors[0] : null;
        $itemType = $detectedItems[0];

        $allProducts = [];
        foreach ($raw['resultsByItem'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach ($block['topProducts'] ?? [] as $p) {
                if (is_array($p)) {
                    $allProducts[] = $this->mapSerpApiUnifiedProduct($p);
                }
            }
        }

        $errors = $raw['debug']['errors'] ?? [];
        if ($allProducts === [] && is_array($errors) && $errors !== []) {
            throw new InspirationAnalysisException((string) $errors[0]);
        }

        if ($allProducts === []) {
            throw new InspirationAnalysisException('Aucun produit trouve pour cette image.');
        }

        $maxProducts = (int) config('vision.limits.max_products_per_item', 10);
        $allProducts = array_slice($allProducts, 0, max(1, $maxProducts));

        $queriesUsed = [];
        foreach ($raw['resultsByItem'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach ($block['queriesUsed'] ?? [] as $q) {
                if (is_string($q) && $q !== '') {
                    $queriesUsed[] = $q;
                }
            }
        }
        $mainQuery = $queriesUsed[0] ?? '';

        $analysis = [
            'style' => [],
            'colors' => array_map('strval', $colors),
            'gender' => 'unisex',
            'items' => array_map(
                fn (string $type) => [
                    'type' => $type,
                    'color' => $color,
                    'material' => null,
                    'brand' => $brand,
                    'confidence' => 0.9,
                ],
                $detectedItems
            ),
        ];

        $scored = $this->scoring->score($allProducts, $analysis);
        $priceSummary = $this->buildPriceSummary($scored);

        return [
            'analysis' => $analysis,
            'scan_results' => $scored,
            'scan_price_summary' => $priceSummary,
            'item_type' => $itemType,
            'brand' => $brand,
            'color' => $color,
            'query' => $mainQuery,
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function mapSerpApiUnifiedProduct(array $p): array
    {
        $title = trim((string) ($p['title'] ?? ''));

        return [
            'id' => (string) ($p['id'] ?? Str::uuid()->toString()),
            'source' => trim(trim((string) ($p['source'] ?? '')).'/'.trim((string) ($p['sourceBlock'] ?? ''))),
            'title' => $title,
            'normalized_title' => trim((string) ($p['normalizedTitle'] ?? '')) ?: mb_strtolower($title),
            'brand' => isset($p['brand']) ? (string) $p['brand'] : null,
            'color' => isset($p['color']) ? (string) $p['color'] : null,
            'price' => isset($p['price']) && is_numeric($p['price']) ? (float) $p['price'] : null,
            'currency' => (string) ($p['currency'] ?? 'EUR'),
            'merchant' => isset($p['merchant']) ? (string) $p['merchant'] : null,
            'product_url' => isset($p['productUrl']) ? (string) $p['productUrl'] : null,
            'image_url' => isset($p['imageUrl']) ? (string) $p['imageUrl'] : null,
            'in_stock' => null,
            'similarity_score' => isset($p['finalScore']) && is_numeric($p['finalScore'])
                ? (float) $p['finalScore']
                : (isset($p['semanticScore']) && is_numeric($p['semanticScore']) ? (float) $p['semanticScore'] : null),
            'semantic_score' => isset($p['semanticScore']) && is_numeric($p['semanticScore']) ? (float) $p['semanticScore'] : null,
            'final_score' => isset($p['finalScore']) && is_numeric($p['finalScore']) ? (float) $p['finalScore'] : null,
            'rank_label' => null,
            'metadata' => is_array($p['metadata'] ?? null) ? $p['metadata'] : [],
        ];
    }

    /**
     * @throws InspirationAnalysisException
     */
    private function executeLegacyPhp(string $imageUrl, string $base64Image, string $mimeType): array
    {
        $debug = (bool) config('vision.debug_mode', false);

        $analysis = $this->imageAnalysis->analyze($base64Image, $mimeType);

        if ($debug) {
            Log::info('Pipeline: analyse GPT-4o', ['analysis' => $analysis]);
        }

        $queries = $this->queryGeneration->generate($analysis);

        if ($queries === []) {
            throw new InspirationAnalysisException('Aucun vetement detecte dans l\'image.');
        }

        $primaryItem = $analysis['items'][0] ?? [];
        $itemType = (string) ($primaryItem['type'] ?? 'vetement');
        $brand = isset($primaryItem['brand']) ? (string) $primaryItem['brand'] : null;
        $color = isset($primaryItem['color']) ? (string) $primaryItem['color'] : null;

        $allRawResults = [];
        $mainQuery = '';

        foreach ($queries as $querySet) {
            $lensResults = $this->lens->searchByImage($imageUrl);
            $allRawResults = array_merge($allRawResults, $lensResults);

            $mainQuery = $querySet['primary'];
            $shoppingResults = $this->shopping->searchByQuery($mainQuery);
            $allRawResults = array_merge($allRawResults, $shoppingResults);

            $minResults = (int) config('vision.limits.min_results_before_fallback', 3);
            if (count($allRawResults) < $minResults) {
                $fallbackResults = $this->shopping->searchByQuery($querySet['fallback']);
                $allRawResults = array_merge($allRawResults, $fallbackResults);

                if ($debug) {
                    Log::info('Pipeline: fallback active', ['query' => $querySet['fallback'], 'count' => count($fallbackResults)]);
                }
            }

            break;
        }

        if ($debug) {
            Log::info('Pipeline: resultats bruts', ['count' => count($allRawResults)]);
        }

        $normalized = $this->normalization->normalize($allRawResults);
        $deduplicated = $this->deduplication->deduplicate($normalized);
        $scored = $this->scoring->score($deduplicated, $analysis);

        $maxProducts = (int) config('vision.limits.max_products_per_item', 5);
        $finalProducts = array_slice($scored, 0, $maxProducts);

        $priceSummary = $this->buildPriceSummary($finalProducts);

        return [
            'analysis' => $analysis,
            'scan_results' => $finalProducts,
            'scan_price_summary' => $priceSummary,
            'item_type' => $itemType,
            'brand' => $brand,
            'color' => $color,
            'query' => $mainQuery,
        ];
    }

    /**
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
                'min_price' => null,
                'max_price' => null,
                'avg_price' => null,
                'currency' => $currency,
                'total_found' => count($products),
            ];
        }

        return [
            'min_price' => round(min($prices), 2),
            'max_price' => round(max($prices), 2),
            'avg_price' => round(array_sum($prices) / count($prices), 2),
            'currency' => $currency,
            'total_found' => count($products),
        ];
    }
}
