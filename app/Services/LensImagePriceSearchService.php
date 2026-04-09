<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\LensIdentificationFailedException;
use App\Support\UnwrapGoogleUrl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 1) GPT-4o vision sur les octets (base64) → détail article + requêtes Shopping
 * 2) Google Shopping (EN puis FR) + Google Lens (URL publique) → fusion
 * 3) Filtre prix + domaine + hint couleur → tri
 * 4) GPT final → results / price_analysis legacy
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

        $en = trim((string) ($itemDetails['search_query_en'] ?? ''));
        $fr = trim((string) ($itemDetails['search_query_fr'] ?? ''));
        $queries = array_values(array_unique(array_filter([$en, $fr], fn (string $q): bool => $q !== '')));

        $allRaw = [];

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

        $lensData = $this->serpApi->rawLensResponse($imagePublicUrl, $hl, $gl);
        $lensShopping = $lensData['shopping_results'] ?? [];
        if (is_array($lensShopping)) {
            foreach ($lensShopping as $row) {
                if (is_array($row)) {
                    $allRaw[] = $row;
                }
            }
        }

        $products = $this->filterDedupeColorSort($allRaw, $itemDetails);

        $visualMatches = $lensData['visual_matches'] ?? [];
        if (! is_array($visualMatches)) {
            $visualMatches = [];
        }
        if (count($products) < 3) {
            $products = $this->supplementWithVisualMatches($products, $visualMatches, minRows: 3);
        }

        $products = array_slice($products, 0, 10);

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
     * @param  array<int, mixed>  $allRaw
     * @param  array<string, mixed>  $itemDetails
     * @return array<int, array<string, mixed>>
     */
    private function filterDedupeColorSort(array $allRaw, array $itemDetails): array
    {
        $colorPrimary = Str::lower(trim((string) ($itemDetails['color_primary'] ?? '')));
        $seen = [];
        $products = [];

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
            $colorMatch = $this->titleMatchesColorHint($titleLower, $colorPrimary);

            $products[] = array_merge($p, ['color_match' => $colorMatch]);
            if (count($products) >= 15) {
                break;
            }
        }

        usort($products, function (array $a, array $b): int {
            $ca = ! empty($a['color_match']);
            $cb = ! empty($b['color_match']);
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }

            return ($a['extracted_price'] ?? 0) <=> ($b['extracted_price'] ?? 0);
        });

        return array_values($products);
    }

    private function titleMatchesColorHint(string $titleLower, string $colorPrimaryFr): bool
    {
        $raw = Str::lower(trim($colorPrimaryFr));
        if ($raw === '') {
            return true;
        }

        $map = [
            'noir' => ['black', 'noir', 'schwarz'],
            'blanc' => ['white', 'blanc', 'weiß', 'weiss'],
            'bleu' => ['blue', 'bleu', 'indigo', 'navy', 'marine'],
            'rouge' => ['red', 'rouge'],
            'vert' => ['green', 'vert', 'olive'],
            'gris' => ['grey', 'gray', 'gris'],
            'beige' => ['beige', 'sand', 'cream'],
            'marron' => ['brown', 'marron', 'camel', 'tan'],
            'jaune' => ['yellow', 'jaune'],
            'rose' => ['pink', 'rose'],
            'violet' => ['violet', 'purple', 'pourpre'],
            'orange' => ['orange'],
        ];

        $variants = null;
        if (isset($map[$raw])) {
            $variants = $map[$raw];
        } else {
            foreach ($map as $k => $v) {
                if (Str::contains($raw, $k)) {
                    $variants = $v;
                    break;
                }
            }
        }
        $variants ??= [$raw];
        foreach ($variants as $variant) {
            if ($variant !== '' && str_contains($titleLower, $variant)) {
                return true;
            }
        }

        return false;
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
                'color_match' => true,
            ];
        }

        return $products;
    }
}
