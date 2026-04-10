<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Recherche Google Shopping via SerpAPI (fallback si Lens &lt; 5 résultats).
 */
final class GoogleShoppingService
{
    public function __construct(
        private readonly SerpApiService $serpApi,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 12): array
    {
        $rows = $this->serpApi->googleShoppingSearch($query, $limit);

        return array_map(static function (array $row): array {
            return [
                'title' => $row['title'] ?? '',
                'link' => $row['link'] ?? '',
                'source' => $row['source'] ?? '',
                'thumbnail' => $row['thumbnail_url'] ?? '',
                'extracted_price' => $row['extracted_price'] ?? null,
                'price' => $row['price'] ?? '',
            ];
        }, $rows);
    }
}
