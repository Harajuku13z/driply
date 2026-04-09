<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\UnwrapGoogleUrl;
use Illuminate\Support\Facades\Storage;

class GoogleLensService
{
    public function __construct(
        private readonly SerpApiService $serpApi,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analyzeImage(string $imagePathOrUrl): array
    {
        $url = $this->toPublicUrl($imagePathOrUrl);

        return $this->serpApi->rawLensResponse($url);
    }

    /**
     * @param  array<string, mixed>  $lensResponse
     * @return array<int, array<string, mixed>>
     */
    public function extractProducts(array $lensResponse): array
    {
        $products = [];

        $visual = $lensResponse['visual_matches'] ?? [];
        if (is_array($visual)) {
            foreach ($visual as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $products[] = $this->normalizeLensItem($item, 'visual_match');
            }
        }

        $shopping = $lensResponse['shopping_results'] ?? $lensResponse['products'] ?? [];
        if (is_array($shopping)) {
            foreach ($shopping as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $products[] = $this->normalizeLensItem($item, 'shopping');
            }
        }

        return array_values(array_filter($products));
    }

    /**
     * Uniquement les N premières correspondances visuelles (ordre Google Lens), pour l’analyse prix.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractTopVisualMatches(array $lensResponse, int $limit = 3): array
    {
        $visual = $lensResponse['visual_matches'] ?? [];
        if (! is_array($visual)) {
            return [];
        }

        $items = array_values(array_filter($visual, fn ($item): bool => is_array($item)));
        usort($items, function (array $a, array $b): int {
            $pa = isset($a['position']) ? (int) $a['position'] : 999_999;
            $pb = isset($b['position']) ? (int) $b['position'] : 999_999;

            return $pa <=> $pb;
        });

        $out = [];
        foreach ($items as $item) {
            $out[] = $this->normalizeLensItem($item, 'visual_match');
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function toPublicUrl(string $imagePathOrUrl): string
    {
        if (str_starts_with($imagePathOrUrl, 'http://') || str_starts_with($imagePathOrUrl, 'https://')) {
            return $imagePathOrUrl;
        }

        return Storage::disk('public')->url($imagePathOrUrl);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeLensItem(array $item, string $sourceKind): array
    {
        $title = (string) ($item['title'] ?? $item['name'] ?? $item['query'] ?? '');
        $imageFull = (string) ($item['image'] ?? '');
        $thumbnail = (string) ($item['thumbnail'] ?? '');
        if ($thumbnail === '' && $imageFull !== '') {
            $thumbnail = $imageFull;
        }
        $link = UnwrapGoogleUrl::unwrap((string) ($item['link'] ?? $item['url'] ?? $item['product_link'] ?? ''));
        $source = UnwrapGoogleUrl::unwrap((string) ($item['source'] ?? $item['seller'] ?? $item['displayed_link'] ?? ''));

        [$priceFound, $currencyFound] = $this->extractPriceAndCurrency($item);

        return [
            'title' => $title,
            'source' => $source,
            'thumbnail_url' => $thumbnail,
            'image_url' => $imageFull !== '' ? $imageFull : $thumbnail,
            'product_url' => $link,
            'price_found' => $priceFound,
            'currency_found' => $currencyFound,
            '_kind' => $sourceKind,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: ?string, 1: ?string}
     */
    private function extractPriceAndCurrency(array $item): array
    {
        $price = $item['price'] ?? null;
        if (is_array($price)) {
            $value = $price['value'] ?? $price['text'] ?? null;
            $extracted = $price['extracted_value'] ?? null;
            $currency = $price['currency'] ?? null;
            if (is_numeric($extracted)) {
                return [(string) ($extracted + 0), $currency !== null && is_scalar($currency) ? (string) $currency : null];
            }
            if ($value !== null && is_scalar($value)) {
                return [(string) $value, $currency !== null && is_scalar($currency) ? (string) $currency : null];
            }

            return [null, $currency !== null && is_scalar($currency) ? (string) $currency : null];
        }

        if ($price !== null && is_scalar($price)) {
            $curr = $item['currency'] ?? null;

            return [(string) $price, $curr !== null && is_scalar($curr) ? (string) $curr : null];
        }

        $ext = $item['extracted_price'] ?? null;
        if (is_numeric($ext)) {
            $curr = $item['extracted_currency'] ?? $item['currency'] ?? null;

            return [(string) ($ext + 0), $curr !== null && is_scalar($curr) ? (string) $curr : null];
        }

        return [null, null];
    }
}
