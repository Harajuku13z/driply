<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Calcule un score de pertinence (0-100) pour chaque produit
 * et assigne des rank_labels aux 5 meilleurs.
 */
class ScoringService
{
    /**
     * Score et classe les produits.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  array{style: list<string>, colors: list<string>, gender: string, items: list<array{type: string, color: ?string, material: ?string, brand: ?string, confidence: float}>}  $analysis
     * @return list<array<string, mixed>>
     */
    public function score(array $products, array $analysis): array
    {
        if ($products === []) {
            return [];
        }

        /** @var array<string, int> $weights */
        $weights = (array) config('vision.weights', []);

        /** @var array<string, list<string>> $colorMap */
        $colorMap = (array) config('vision.color_map', []);

        $analysisColors = array_map('strtolower', $analysis['colors'] ?? []);
        $analysisTypes  = array_map(fn (array $item) => strtolower(trim((string) ($item['type'] ?? ''))), $analysis['items'] ?? []);

        // Score chaque produit
        foreach ($products as &$product) {
            $score = 0;

            // has_price (20)
            if (($product['price'] ?? null) !== null && (float) $product['price'] > 0) {
                $score += $weights['has_price'] ?? 20;
            }

            // color_match (20)
            if ($this->matchesColor($product, $analysisColors, $colorMap)) {
                $score += $weights['color_match'] ?? 20;
            }

            // category_match (15)
            if ($this->matchesCategory($product, $analysisTypes)) {
                $score += $weights['category_match'] ?? 15;
            }

            // has_image (10)
            if (! empty($product['image_url'])) {
                $score += $weights['has_image'] ?? 10;
            }

            // has_brand (10)
            if (! empty($product['brand'])) {
                $score += $weights['has_brand'] ?? 10;
            }

            // has_merchant (10)
            if (! empty($product['merchant'])) {
                $score += $weights['has_merchant'] ?? 10;
            }

            // in_stock (8)
            if (($product['in_stock'] ?? null) === true) {
                $score += $weights['in_stock'] ?? 8;
            }

            // similarity_score (7) — proportionnel au score original
            $simScore = (float) ($product['similarity_score'] ?? 0);
            if ($simScore > 0) {
                $maxSim = $weights['similarity_score'] ?? 7;
                $score += (int) round($simScore / 100 * $maxSim);
            }

            $product['final_score'] = min($score, 100);
        }
        unset($product);

        // Trier par final_score DESC
        usort($products, fn (array $a, array $b) => ($b['final_score'] ?? 0) <=> ($a['final_score'] ?? 0));

        // Assigner rank_labels sur les top 5 tries par prix ASC
        $products = $this->assignRankLabels($products);

        return $products;
    }

    /**
     * Verifie si le produit correspond a une couleur detectee.
     *
     * @param  list<string>  $analysisColors
     * @param  array<string, list<string>>  $colorMap
     */
    private function matchesColor(array $product, array $analysisColors, array $colorMap): bool
    {
        if ($analysisColors === []) {
            return false;
        }

        $titleLower = strtolower((string) ($product['normalized_title'] ?? ($product['title'] ?? '')));

        foreach ($analysisColors as $color) {
            // Chercher directement dans le titre
            if ($color !== '' && str_contains($titleLower, $color)) {
                return true;
            }

            // Chercher via la color_map (synonymes)
            foreach ($colorMap as $canonical => $synonyms) {
                if (in_array($color, $synonyms, true) || $color === $canonical) {
                    foreach ($synonyms as $synonym) {
                        if (str_contains($titleLower, $synonym)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Verifie si le produit correspond a un type de vetement detecte.
     *
     * @param  list<string>  $analysisTypes
     */
    private function matchesCategory(array $product, array $analysisTypes): bool
    {
        if ($analysisTypes === []) {
            return false;
        }

        $titleLower = strtolower((string) ($product['normalized_title'] ?? ($product['title'] ?? '')));

        foreach ($analysisTypes as $type) {
            if ($type !== '' && str_contains($titleLower, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assigne les rank_labels aux 5 meilleurs produits (tries par prix ASC).
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function assignRankLabels(array $products): array
    {
        /** @var array<int, string> $labels */
        $labels = (array) config('vision.rank_labels', []);

        $maxProducts = (int) config('vision.limits.max_products_per_item', 5);

        // Prendre les N meilleurs (deja tries par score)
        $topCount = min($maxProducts, count($products));
        $topIndices = array_slice(array_keys($products), 0, $topCount);

        // Trier ces top produits par prix ASC pour assigner les labels
        $topProducts = [];
        foreach ($topIndices as $i) {
            $topProducts[] = ['index' => $i, 'price' => (float) ($products[$i]['price'] ?? PHP_FLOAT_MAX)];
        }

        usort($topProducts, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        foreach ($topProducts as $rank => $entry) {
            $products[$entry['index']]['rank_label'] = $labels[$rank] ?? null;
        }

        return $products;
    }
}
