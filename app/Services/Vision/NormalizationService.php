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
            $imageUrl = $this->resolveBestImageUrl($raw);

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
                'image_url'        => $imageUrl,
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
     * Choisit la meilleure URL d’image disponible (Lens : champ `image` ; Shopping : serpapi_thumbnails).
     *
     * @param  array<string, mixed>  $raw
     */
    private function resolveBestImageUrl(array $raw): ?string
    {
        $fallback = trim((string) ($raw['thumbnail'] ?? ''));
        $nested = $raw['raw'] ?? null;
        if (! is_array($nested)) {
            return $fallback !== '' ? $fallback : null;
        }

        if (($raw['source'] ?? '') === 'google_shopping') {
            $best = $this->bestGoogleShoppingImageFromItem($nested);

            return $best !== '' ? $best : ($fallback !== '' ? $fallback : null);
        }

        $best = SerpApiImageUrlSelector::pickBest(
            array_merge(
                SerpApiImageUrlSelector::lensVisualMatchCandidates($nested),
                $fallback !== '' ? [$fallback] : []
            )
        );

        return $best !== '' ? $best : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function bestGoogleShoppingImageFromItem(array $item): string
    {
        return SerpApiImageUrlSelector::pickBest(
            SerpApiImageUrlSelector::shoppingImageCandidates($item)
        );
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
