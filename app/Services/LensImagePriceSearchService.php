<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UnwrapGoogleUrl;
use Illuminate\Support\Str;

/**
 * Flux demandé : Lens SerpAPI (visual_matches + shopping_results) → Shopping de secours si besoin
 * → produits formatés → analyse GPT (fourchette + top_3_picks).
 */
class LensImagePriceSearchService
{
    public function __construct(
        private readonly SerpApiService $serpApi,
        private readonly PriceAnalysisService $priceAnalysis,
    ) {}

    /**
     * @return array{
     *     all_products: array<int, array<string, mixed>>,
     *     price_analysis: array<string, mixed>,
     *     top_3: array<int, array<string, mixed>>
     * }
     */
    public function searchAndAnalyze(string $imagePublicUrl, string $currency): array
    {
        $hl = (string) config('driply.lens.shopping_hl', 'fr');
        $gl = (string) config('driply.lens.shopping_gl', 'fr');

        $lensData = $this->serpApi->rawLensResponse($imagePublicUrl, $hl, $gl);
        $shoppingResults = $lensData['shopping_results'] ?? [];
        if (! is_array($shoppingResults)) {
            $shoppingResults = [];
        }
        $visualMatches = $lensData['visual_matches'] ?? [];
        if (! is_array($visualMatches)) {
            $visualMatches = [];
        }

        if ($shoppingResults === [] && $visualMatches !== []) {
            $query = $this->firstVisualMatchTitle($visualMatches);
            if ($query !== '') {
                $fetched = $this->serpApi->googleShoppingSearch($query, 20, $gl, $hl);
                foreach ($fetched as $parsed) {
                    $shoppingResults[] = $this->parsedOfferToPseudoRawRow($parsed);
                }
            }
        }

        $products = $this->formatAllProductsFromShoppingRows($shoppingResults);
        if (count($products) < 3) {
            $products = $this->supplementWithVisualMatches($products, $visualMatches, minRows: 3);
        }

        $products = array_slice($products, 0, 10);

        $analysis = $this->priceAnalysis->analyzeLensProductList($products, $currency);
        /** @var array<int, array<string, mixed>> $top3 */
        $top3 = is_array($analysis['top_3_picks'] ?? null) ? $analysis['top_3_picks'] : [];

        return [
            'all_products' => $products,
            'price_analysis' => $analysis,
            'top_3' => $top3,
        ];
    }

    /**
     * @param  array<int, mixed>  $visualMatches
     */
    private function firstVisualMatchTitle(array $visualMatches): string
    {
        $sorted = array_values(array_filter($visualMatches, fn ($i): bool => is_array($i)));
        usort($sorted, fn (array $a, array $b): int => ((int) ($a['position'] ?? 999_999)) <=> ((int) ($b['position'] ?? 999_999)));
        $first = $sorted[0] ?? null;
        if (! is_array($first)) {
            return '';
        }

        return Str::limit(trim((string) ($first['title'] ?? '')), 220, '');
    }

    /**
     * @param  array<string, mixed>  $parsed  Format googleShoppingSearch()
     * @return array<string, mixed>
     */
    private function parsedOfferToPseudoRawRow(array $parsed): array
    {
        $row = [
            'title' => $parsed['title'] ?? '',
            'link' => $parsed['link'] ?? '',
            'source' => $parsed['source'] ?? '',
            'thumbnail' => $parsed['thumbnail_url'] ?? '',
        ];
        if (isset($parsed['extracted_price']) && is_numeric($parsed['extracted_price'])) {
            $row['extracted_price'] = $parsed['extracted_price'] + 0;
        }
        if (($parsed['price'] ?? null) !== null) {
            $row['price'] = $parsed['price'];
        }
        if (($parsed['currency'] ?? null) !== null) {
            $row['currency'] = $parsed['currency'];
        }

        return $row;
    }

    /**
     * @param  array<int, mixed>  $shoppingResults
     * @return array<int, array<string, mixed>>
     */
    private function formatAllProductsFromShoppingRows(array $shoppingResults): array
    {
        $out = [];
        $seenLinks = [];
        foreach ($shoppingResults as $row) {
            if (! is_array($row)) {
                continue;
            }
            $p = $this->serpApi->catalogProductFromSerpRow($row);
            if ($p === null) {
                continue;
            }
            $link = (string) ($p['link'] ?? '');
            if ($link === '' || isset($seenLinks[$link])) {
                continue;
            }
            $seenLinks[$link] = true;
            $out[] = $p;
        }

        return array_values($out);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, mixed>  $visualMatches
     * @return array<int, array<string, mixed>>
     */
    private function supplementWithVisualMatches(array $products, array $visualMatches, int $minRows): array
    {
        if (count($products) >= $minRows) {
            return $products;
        }

        $seen = [];
        foreach ($products as $p) {
            $seen[(string) ($p['link'] ?? '')] = true;
        }

        $items = array_values(array_filter($visualMatches, fn ($i): bool => is_array($i)));
        usort($items, fn (array $a, array $b): int => ((int) ($a['position'] ?? 999_999)) <=> ((int) ($b['position'] ?? 999_999)));

        foreach ($items as $vm) {
            if (count($products) >= $minRows) {
                break;
            }
            if (! is_array($vm)) {
                continue;
            }
            $link = UnwrapGoogleUrl::unwrap((string) ($vm['link'] ?? $vm['url'] ?? ''));
            if ($link === '' || isset($seen[$link])) {
                continue;
            }
            $seen[$link] = true;
            $thumb = (string) ($vm['thumbnail'] ?? '');
            $image = (string) ($vm['image'] ?? '');
            $ext = $vm['extracted_price'] ?? null;
            $priceStr = null;
            if (is_numeric($ext)) {
                $priceStr = (string) ($ext + 0);
            }
            $products[] = [
                'title' => trim((string) ($vm['title'] ?? '')) !== '' ? (string) $vm['title'] : 'Sans titre',
                'price' => $priceStr,
                'extracted_price' => is_numeric($ext) ? $ext + 0 : null,
                'currency' => 'EUR',
                'source' => (string) ($vm['source'] ?? null),
                'link' => $link,
                'thumbnail' => $thumb !== '' ? $thumb : ($image !== '' ? $image : null),
                'rating' => isset($vm['rating']) && is_numeric($vm['rating']) ? $vm['rating'] + 0 : null,
                'reviews' => isset($vm['reviews']) && is_numeric($vm['reviews']) ? (int) $vm['reviews'] : null,
            ];
        }

        return $products;
    }
}
