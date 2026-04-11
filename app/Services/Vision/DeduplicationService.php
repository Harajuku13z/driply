<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Fusionne et dedoublonne les produits provenant de sources multiples.
 */
class DeduplicationService
{
    /**
     * Dedoublonne les produits et retourne max 15 uniques.
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    public function deduplicate(array $products): array
    {
        if (count($products) <= 1) {
            return $products;
        }

        /** @var list<array<string, mixed>> $unique */
        $unique = [];

        foreach ($products as $product) {
            $duplicateIndex = $this->findDuplicate($product, $unique);

            if ($duplicateIndex !== null) {
                $unique[$duplicateIndex] = $this->merge($unique[$duplicateIndex], $product);
            } else {
                $unique[] = $product;
            }
        }

        return array_slice($unique, 0, 15);
    }

    /**
     * Cherche un doublon dans la liste deja traitee.
     *
     * @param  list<array<string, mixed>>  $existing
     */
    private function findDuplicate(array $product, array $existing): ?int
    {
        $productUrl = $this->canonicalUrl((string) ($product['product_url'] ?? ''));
        $productMerchant = strtolower((string) ($product['merchant'] ?? ''));
        $productPrice = (float) ($product['price'] ?? 0);
        $productTitle = (string) ($product['normalized_title'] ?? '');

        foreach ($existing as $index => $item) {
            // Critere 1 : meme URL canonique
            $existingUrl = $this->canonicalUrl((string) ($item['product_url'] ?? ''));
            if ($productUrl !== '' && $existingUrl !== '' && $productUrl === $existingUrl) {
                return $index;
            }

            // Critere 2 : meme marchand + prix identique (+-5%)
            $existingMerchant = strtolower((string) ($item['merchant'] ?? ''));
            $existingPrice = (float) ($item['price'] ?? 0);

            if (
                $productMerchant !== ''
                && $existingMerchant !== ''
                && $productMerchant === $existingMerchant
                && $productPrice > 0
                && $existingPrice > 0
                && abs($productPrice - $existingPrice) / max($productPrice, $existingPrice) <= 0.05
            ) {
                return $index;
            }

            // Critere 3 : titre similaire 85%+ ET meme fourchette de prix
            $existingTitle = (string) ($item['normalized_title'] ?? '');
            if ($productTitle !== '' && $existingTitle !== '') {
                $similarity = 0;
                similar_text($productTitle, $existingTitle, $similarity);

                if (
                    $similarity >= 85
                    && ($productPrice === 0.0 || $existingPrice === 0.0
                        || abs($productPrice - $existingPrice) / max($productPrice, $existingPrice) <= 0.15)
                ) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Fusionne deux produits en gardant les meilleures donnees.
     *
     * @return array<string, mixed>
     */
    private function merge(array $existing, array $duplicate): array
    {
        // Garder le titre le plus complet
        if (strlen((string) ($duplicate['title'] ?? '')) > strlen((string) ($existing['title'] ?? ''))) {
            $existing['title'] = $duplicate['title'];
            $existing['normalized_title'] = $duplicate['normalized_title'];
        }

        // Garder le prix si disponible (priorite Shopping)
        if (($existing['price'] ?? null) === null && ($duplicate['price'] ?? null) !== null) {
            $existing['price'] = $duplicate['price'];
            $existing['currency'] = $duplicate['currency'] ?? $existing['currency'];
        }
        if (($duplicate['source'] ?? '') === 'google_shopping' && ($duplicate['price'] ?? null) !== null) {
            $existing['price'] = $duplicate['price'];
        }

        // Garder l'image si disponible (priorite Lens)
        if (($existing['image_url'] ?? null) === null && ($duplicate['image_url'] ?? null) !== null) {
            $existing['image_url'] = $duplicate['image_url'];
        }
        if (($duplicate['source'] ?? '') === 'google_lens' && ($duplicate['image_url'] ?? null) !== null) {
            $existing['image_url'] = $duplicate['image_url'];
        }

        // Merger les metadata
        $existing['metadata'] = array_merge(
            (array) ($existing['metadata'] ?? []),
            ['merged_from' => (array) ($duplicate['metadata'] ?? [])]
        );

        // Garder le meilleur similarity_score
        $existingScore = (float) ($existing['similarity_score'] ?? 0);
        $duplicateScore = (float) ($duplicate['similarity_score'] ?? 0);
        if ($duplicateScore > $existingScore) {
            $existing['similarity_score'] = $duplicateScore;
        }

        // Brand
        if (empty($existing['brand']) && ! empty($duplicate['brand'])) {
            $existing['brand'] = $duplicate['brand'];
        }

        return $existing;
    }

    /**
     * Canonise une URL pour la comparaison (supprime protocole, trailing slash, params de tracking).
     */
    private function canonicalUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $url = (string) preg_replace('#^https?://(www\.)?#i', '', $url);
        $url = strtok($url, '?') ?: $url;
        $url = rtrim($url, '/');

        return strtolower($url);
    }
}
