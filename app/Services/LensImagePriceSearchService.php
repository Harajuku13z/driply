<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LensIdentificationFailedException;
use App\Support\UnwrapGoogleUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 1) GPT-4o vision (base64) → description « vérité »
 * 2) Google Lens (URL) fusionné en tête des lignes Shopping
 * 3) Google Shopping EN + FR
 * 4) Filtre domaine + couleur (priorité équivalentes couleur) → max 12
 * 5) GPT final → exactement 3 résultats (normalisation côté serveur)
 */
class LensImagePriceSearchService
{
    /**
     * @return array<string, list<string>>
     */
    private static function colorDictionary(): array
    {
        return [
            'noir' => ['black', 'noir', 'dark', 'ebony'],
            'blanc' => ['white', 'blanc', 'ivory', 'cream', 'off-white'],
            'bleu' => ['blue', 'bleu', 'navy', 'indigo', 'cobalt', 'denim'],
            'bleu indigo' => ['indigo', 'blue', 'denim', 'dark blue', 'bleu'],
            'rouge' => ['red', 'rouge', 'scarlet', 'crimson', 'bordeaux'],
            'vert' => ['green', 'vert', 'olive', 'khaki', 'emerald', 'forest'],
            'gris' => ['grey', 'gray', 'gris', 'charcoal', 'silver'],
            'beige' => ['beige', 'sand', 'cream', 'nude', 'camel', 'tan'],
            'marron' => ['brown', 'marron', 'camel', 'tan', 'chocolate'],
            'rose' => ['pink', 'rose', 'blush', 'fuchsia', 'coral'],
            'jaune' => ['yellow', 'jaune', 'mustard', 'gold'],
            'orange' => ['orange', 'rust', 'terracotta'],
            'violet' => ['purple', 'violet', 'lavender', 'lilac', 'mauve'],
        ];
    }

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
    public function searchAndAnalyze(string $relativePublicPath, string $imagePublicUrl, string $currency): array
    {
        if (! Storage::disk('public')->exists($relativePublicPath)) {
            throw new LensIdentificationFailedException('Image introuvable après enregistrement.');
        }

        $bytes = Storage::disk('public')->get($relativePublicPath);
        if ($bytes === false || $bytes === '') {
            throw new LensIdentificationFailedException('Image introuvable après enregistrement.');
        }

        $mime = $this->mimeFromPublicPath($relativePublicPath);
        $itemDetails = $this->visionQuery->extractStructuredItemFromBytes($bytes, $mime);

        $hl = (string) config('driply.lens.shopping_hl', 'fr');
        $gl = (string) config('driply.lens.shopping_gl', 'fr');
        $shoppingNum = (int) config('driply.lens.shopping_fetch_limit', 20);

        $en = trim((string) ($itemDetails['search_query_google_en'] ?? $itemDetails['search_query_en'] ?? ''));
        $fr = trim((string) ($itemDetails['search_query_google_fr'] ?? $itemDetails['search_query_fr'] ?? ''));
        $queries = array_values(array_unique(array_filter([$en, $fr], fn (string $q): bool => $q !== '')));

        $allRaw = [];

        $lensData = $this->serpApi->rawLensResponse($imagePublicUrl, $hl, $gl);
        $lensShopping = $lensData['shopping_results'] ?? [];
        if (is_array($lensShopping)) {
            foreach ($lensShopping as $row) {
                if (is_array($row)) {
                    $allRaw[] = $row;
                }
            }
        }

        foreach ($queries as $query) {
            $rows = $this->serpApi->googleShoppingRawRows($query, $shoppingNum, $gl, $hl);
            $addedWithPrice = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $allRaw[] = $row;
                if ($this->serpApi->shoppingRowHasExtractedPrice($row)) {
                    $addedWithPrice++;
                }
            }
            if ($addedWithPrice >= 10) {
                break;
            }
        }

        $products = $this->filterDedupeColorBuckets($allRaw, $itemDetails);

        $visualMatches = $lensData['visual_matches'] ?? [];
        if (! is_array($visualMatches)) {
            $visualMatches = [];
        }
        if (count($products) < 3) {
            $products = $this->supplementWithVisualMatches($products, $visualMatches, minRows: 3);
        }

        $products = array_slice($products, 0, 12);

        $searchLabel = implode(' | ', $queries);

        $analysis = $this->priceAnalysis->finalizeLensShoppingFromVision($itemDetails, $products, $currency, $searchLabel);
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
            'query_used' => $searchLabel,
            'item_detected' => (string) ($analysis['item_identified'] ?? ''),
            'brand' => $brand,
            'color' => isset($analysis['color']) ? (string) $analysis['color'] : null,
            'price_summary' => $priceSummary,
        ];
    }

    private function mimeFromPublicPath(string $relative): string
    {
        $ext = Str::lower((string) pathinfo($relative, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    /**
     * @return list<string>
     */
    private function colorVariantsForPrimary(string $colorPrimary): array
    {
        $raw = Str::lower(trim($colorPrimary));
        if ($raw === '') {
            return [];
        }
        $dict = self::colorDictionary();
        if (isset($dict[$raw])) {
            return $dict[$raw];
        }
        foreach ($dict as $key => $variants) {
            if (str_contains($raw, $key) || str_contains($key, $raw)) {
                return $variants;
            }
        }

        return [$raw];
    }

    /**
     * @param  array<int, mixed>  $allRaw
     * @param  array<string, mixed>  $itemDetails
     * @return array<int, array<string, mixed>>
     */
    private function filterDedupeColorBuckets(array $allRaw, array $itemDetails): array
    {
        $colorPrimary = trim((string) ($itemDetails['color_primary'] ?? ''));
        $variants = $this->colorVariantsForPrimary($colorPrimary);

        $seen = [];
        $matchingProducts = [];
        $fallbackProducts = [];

        foreach ($allRaw as $row) {
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
            if (! isset($p['extracted_price']) || ! is_numeric($p['extracted_price'])) {
                continue;
            }
            $domain = $this->normalizeHost($link);
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;

            $titleLower = Str::lower((string) ($p['title'] ?? ''));
            $colorFound = false;
            if ($variants !== []) {
                foreach ($variants as $variant) {
                    $v = Str::lower(trim($variant));
                    if ($v !== '' && str_contains($titleLower, $v)) {
                        $colorFound = true;
                        break;
                    }
                }
            } else {
                $colorFound = true;
            }

            $product = array_merge($p, [
                'color_confirmed' => $colorFound,
                'color_match' => $colorFound,
            ]);

            if ($colorFound) {
                $matchingProducts[] = $product;
            } else {
                $fallbackProducts[] = $product;
            }
        }

        usort($matchingProducts, fn (array $a, array $b): int => ($a['extracted_price'] ?? 0) <=> ($b['extracted_price'] ?? 0));
        usort($fallbackProducts, fn (array $a, array $b): int => ($a['extracted_price'] ?? 0) <=> ($b['extracted_price'] ?? 0));

        return array_values(array_slice(array_merge($matchingProducts, $fallbackProducts), 0, 12));
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
                'color_confirmed' => true,
                'color_match' => true,
            ];
        }

        return $products;
    }
}
