<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * Genere les requetes de recherche a partir de l'analyse GPT-4o.
 */
class QueryGenerationService
{
    /**
     * Genere 3 variantes de requete pour chaque item detecte.
     *
     * @param  array{items: list<array{type: string, color: ?string, material: ?string, brand: ?string, confidence: float}>, gender?: string}  $analysis
     * @return array<string, array{primary: string, shopping: string, fallback: string}>
     */
    public function generate(array $analysis): array
    {
        $gender  = $analysis['gender'] ?? 'unisexe';
        $queries = [];

        foreach ($analysis['items'] as $item) {
            $type     = trim((string) ($item['type'] ?? ''));
            $color    = trim((string) ($item['color'] ?? ''));
            $material = trim((string) ($item['material'] ?? ''));
            $brand    = trim((string) ($item['brand'] ?? ''));

            if ($type === '') {
                continue;
            }

            $genderSuffix = match (strtolower($gender)) {
                'femme'  => 'women',
                'homme'  => 'men',
                default  => '',
            };

            // primary : "{color} {material} {brand} {type} {gender}"
            $primaryParts = array_filter([$color, $material, $brand, $type, $genderSuffix]);
            $primary = implode(' ', $primaryParts);

            // shopping : "{brand} {type} {color} buy"
            $shoppingParts = array_filter([$brand, $type, $color, 'buy']);
            $shopping = implode(' ', $shoppingParts);

            // fallback : "{type} {color}"
            $fallbackParts = array_filter([$type, $color]);
            $fallback = implode(' ', $fallbackParts);

            $key = strtolower($type);
            $queries[$key] = [
                'primary'  => $primary,
                'shopping' => $shopping,
                'fallback' => $fallback,
            ];
        }

        return $queries;
    }
}
