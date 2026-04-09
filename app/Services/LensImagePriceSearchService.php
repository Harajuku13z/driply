<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UnwrapGoogleUrl;
use Illuminate\Support\Str;

/**
 * Lens SerpAPI → requête précise (knowledge_graph + visual_matches, vision si peu d’offres)
 * → Google Shopping → fusion + dédoublonnage par domaine → analyse GPT (top_results + price_summary).
 */
class LensImagePriceSearchService
{
    public function __construct(
        private readonly SerpApiService $serpApi,
        private readonly PriceAnalysisService $priceAnalysis,
        private readonly LensImageVisionQueryService $visionQuery,
    ) {}

    /**
     * @return array{
     *     all_products: array<int, array<string, mixed>>,
     *     price_analysis: array<string, mixed>,
     *     top_3: array<int, array<string, mixed>>,
     *     top_results: array<int, array<string, mixed>>,
     *     query_used: string,
     *     item_detected: string,
     *     brand: ?string,
     *     color: ?string,
     *     price_summary: array<string, mixed>|null,
     * }
     */
    public function searchAndAnalyze(string $imagePublicUrl, string $currency): array
    {
        $hl = (string) config('driply.lens.shopping_hl', 'fr');
        $gl = (string) config('driply.lens.shopping_gl', 'fr');
        $visionThreshold = (int) config('driply.lens.vision_shopping_threshold', 3);
        $shoppingLimit = (int) config('driply.lens.shopping_fetch_limit', 20);

        $lensData = $this->serpApi->rawLensResponse($imagePublicUrl, $hl, $gl);
        $lensShopping = $lensData['shopping_results'] ?? [];
        if (! is_array($lensShopping)) {
            $lensShopping = [];
        }
        $visualMatches = $lensData['visual_matches'] ?? [];
        if (! is_array($visualMatches)) {
            $visualMatches = [];
        }

        $searchQuery = $this->buildPreciseSearchQuery($lensData, $visualMatches);

        if (count($lensShopping) < $visionThreshold && $searchQuery !== '') {
            $searchQuery = $this->visionQuery->preciseShoppingQuery($imagePublicUrl, $searchQuery);
        }

        if ($searchQuery === '') {
            $searchQuery = $this->firstVisualMatchTitle($visualMatches);
        }

        $mergedRaw = $lensShopping;
        if ($searchQuery !== '') {
            $fetched = $this->serpApi->googleShoppingSearch($searchQuery, $shoppingLimit, $gl, $hl);
            foreach ($fetched as $parsed) {
                $mergedRaw[] = $this->parsedOfferToPseudoRawRow($parsed);
            }
        }

        $products = $this->catalogRowsDedupeByHostSortByPrice($mergedRaw);
        if (count($products) < 3) {
            $products = $this->supplementWithVisualMatches($products, $visualMatches, minRows: 3);
        }

        $products = array_slice($products, 0, 10);

        $analysis = $this->priceAnalysis->analyzeLensProductList($products, $currency, $searchQuery);
        /** @var array<int, array<string, mixed>> $top3 */
        $top3 = is_array($analysis['top_3_picks'] ?? null) ? $analysis['top_3_picks'] : [];
        /** @var array<int, array<string, mixed>> $topResults */
        $topResults = is_array($analysis['top_results'] ?? null) ? $analysis['top_results'] : [];
        $priceSummary = is_array($analysis['price_summary'] ?? null) ? $analysis['price_summary'] : null;

        $brand = isset($analysis['brand']) ? (is_string($analysis['brand']) ? $analysis['brand'] : null) : null;
        if ($brand === '') {
            $brand = null;
        }

        return [
            'all_products' => $products,
            'price_analysis' => $analysis,
            'top_3' => $top3,
            'top_results' => $topResults,
            'query_used' => (string) ($analysis['search_query_used'] ?? $searchQuery),
            'item_detected' => (string) ($analysis['item_identified'] ?? $searchQuery),
            'brand' => $brand,
            'color' => isset($analysis['color']) ? (string) $analysis['color'] : null,
            'price_summary' => $priceSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $lensData
     * @param  array<int, mixed>  $visualMatches
     */
    private function buildPreciseSearchQuery(array $lensData, array $visualMatches): string
    {
        $kg = $lensData['knowledge_graph'] ?? [];
        if (! is_array($kg)) {
            $kg = [];
        }
        $kgTitle = trim((string) ($kg['title'] ?? ''));
        $kgSubtitle = trim((string) ($kg['subtitle'] ?? ''));
        $kgSource = trim((string) ($kg['source'] ?? $kg['type'] ?? ''));

        $parts = [];
        if ($kgTitle !== '') {
            $parts[] = $kgTitle;
        }
        if ($kgSubtitle !== '' && ! Str::contains(Str::lower($kgTitle), Str::lower($kgSubtitle))) {
            $parts[] = $kgSubtitle;
        }
        if ($kgSource !== '' && ! Str::contains(Str::lower(implode(' ', $parts)), Str::lower($kgSource))) {
            $parts[] = $kgSource;
        }

        if ($parts !== []) {
            return Str::limit(trim(implode(' ', $parts)), 220, '');
        }

        return $this->firstVisualMatchTitle($visualMatches);
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
     * Un produit par domaine, priorité aux prix les plus bas.
     *
     * @param  array<int, mixed>  $shoppingResults
     * @return array<int, array<string, mixed>>
     */
    private function catalogRowsDedupeByHostSortByPrice(array $shoppingResults): array
    {
        $candidates = [];
        foreach ($shoppingResults as $row) {
            if (! is_array($row)) {
                continue;
            }
            $p = $this->serpApi->catalogProductFromSerpRow($row);
            if ($p === null) {
                continue;
            }
            $link = (string) ($p['link'] ?? '');
            if ($link === '') {
                continue;
            }
            $host = $this->normalizeHost($link);
            if ($host === '') {
                continue;
            }
            $price = isset($p['extracted_price']) && is_numeric($p['extracted_price']) ? (float) $p['extracted_price'] : null;
            $candidates[] = ['host' => $host, 'price' => $price ?? 1e12, 'p' => $p];
        }

        usort($candidates, fn (array $a, array $b): int => $a['price'] <=> $b['price']);

        $seen = [];
        $out = [];
        foreach ($candidates as $c) {
            $h = $c['host'];
            if (isset($seen[$h])) {
                continue;
            }
            $seen[$h] = true;
            $out[] = $c['p'];
            if (count($out) >= 15) {
                break;
            }
        }

        return array_values($out);
    }

    private function normalizeHost(string $url): string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $host = Str::lower($host);

        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
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

        $seenHosts = [];
        foreach ($products as $p) {
            $link = (string) ($p['link'] ?? '');
            if ($link !== '') {
                $seenHosts[$this->normalizeHost($link)] = true;
            }
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
            if ($link === '') {
                continue;
            }
            $host = $this->normalizeHost($link);
            if ($host === '' || isset($seenHosts[$host])) {
                continue;
            }
            $seenHosts[$host] = true;
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
