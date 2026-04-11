<?php

declare(strict_types=1);

namespace App\Services\Vision;

use Illuminate\Support\Str;

/**
 * Normalise les resultats bruts (Lens + Shopping) en format unifie.
 */
class NormalizationService
{
    /**
     * Transforme chaque resultat brut en structure unifiee.
     *
     * @param  list<array<string, mixed>>  $rawResults
     * @return list<array<string, mixed>>
     */
    public function normalize(array $rawResults): array
    {
        $normalized = [];

        foreach ($rawResults as $raw) {
            $title    = trim((string) ($raw['title'] ?? ''));
            $link     = trim((string) ($raw['link'] ?? ''));
            $merchant = $this->extractMerchant($link, (string) ($raw['source_name'] ?? ''));

            $normalized[] = [
                'id'               => Str::uuid()->toString(),
                'source'           => (string) ($raw['source'] ?? 'unknown'),
                'title'            => $title,
                'normalized_title' => $this->normalizeTitle($title),
                'brand'            => $this->extractBrand($raw),
                'color'            => null,
                'price'            => isset($raw['price']) ? (float) $raw['price'] : null,
                'currency'         => (string) ($raw['currency'] ?? 'EUR'),
                'merchant'         => $merchant,
                'product_url'      => $link !== '' ? $link : null,
                'image_url'        => trim((string) ($raw['thumbnail'] ?? '')) ?: null,
                'in_stock'         => $raw['in_stock'] ?? null,
                'similarity_score' => isset($raw['similarity_score']) ? (float) $raw['similarity_score'] : null,
                'semantic_score'   => null,
                'final_score'      => null,
                'rank_label'       => null,
                'metadata'         => $raw['raw'] ?? [],
            ];
        }

        return $normalized;
    }

    /**
     * Normalise un titre : minuscule, trim, suppression de mots en double.
     */
    private function normalizeTitle(string $title): string
    {
        $lower = mb_strtolower(trim($title));
        $words = preg_split('/\s+/', $lower) ?: [];
        $unique = array_values(array_unique($words));

        return implode(' ', $unique);
    }

    /**
     * Extrait le domaine marchand depuis l'URL.
     */
    private function extractMerchant(string $url, string $sourceName): ?string
    {
        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return str_replace('www.', '', $host);
            }
        }

        return $sourceName !== '' ? $sourceName : null;
    }

    /**
     * Tente d'extraire la marque depuis les donnees brutes.
     */
    private function extractBrand(array $raw): ?string
    {
        if (! empty($raw['raw']['brand'])) {
            return (string) $raw['raw']['brand'];
        }

        if (! empty($raw['source_name'])) {
            $source = (string) $raw['source_name'];
            $knownBrands = ['Zara', 'H&M', 'Nike', 'Adidas', 'Uniqlo', 'Mango', 'ASOS', 'Shein'];
            foreach ($knownBrands as $brand) {
                if (stripos($source, $brand) !== false) {
                    return $brand;
                }
            }
        }

        return null;
    }
}
