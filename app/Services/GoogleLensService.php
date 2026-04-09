<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class GoogleLensService
{
    public function __construct(
        private readonly SerpApiService $serpApi,
    ) {
    }

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
        $thumbnail = (string) ($item['thumbnail'] ?? $item['image'] ?? '');
        $link = (string) ($item['link'] ?? $item['url'] ?? $item['product_link'] ?? '');
        $source = (string) ($item['source'] ?? $item['seller'] ?? $item['displayed_link'] ?? '');
        $price = $item['price'] ?? $item['extracted_price'] ?? null;
        $currency = $item['currency'] ?? $item['extracted_currency'] ?? null;

        return [
            'title' => $title,
            'source' => $source,
            'thumbnail_url' => $thumbnail,
            'product_url' => $link,
            'price_found' => $price !== null ? (string) $price : null,
            'currency_found' => $currency !== null ? (string) $currency : null,
            '_kind' => $sourceKind,
        ];
    }
}
