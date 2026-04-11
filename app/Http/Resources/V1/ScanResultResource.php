<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formate la reponse JSON d'un scan Vision.
 *
 * @mixin \App\Models\Inspiration
 */
class ScanResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'type'               => $this->type?->value ?? 'scan',
            'title'              => $this->title,
            'thumbnail_url'      => $this->thumbnail_url,
            'scan_query'         => $this->scan_query,
            'scan_item_type'     => $this->scan_item_type,
            'scan_brand'         => $this->scan_brand,
            'scan_color'         => $this->scan_color,
            'scan_results'       => $this->formatScanResults(),
            'scan_price_summary' => $this->scan_price_summary,
            'status'             => $this->status?->value ?? 'processed',
            'created_at'         => $this->created_at?->toIso8601String(),
            'groupe_ids'         => $this->whenLoaded('groupes', fn () => $this->groupes->pluck('id')->values()->all(), []),
        ];
    }

    /**
     * Formate les resultats du scan en ne gardant que les champs utiles pour le client.
     *
     * @return list<array<string, mixed>>
     */
    private function formatScanResults(): array
    {
        $results = $this->scan_results;

        if (! is_array($results)) {
            return [];
        }

        return array_map(fn (array $product) => [
            'id'               => $product['id'] ?? null,
            'title'            => $product['title'] ?? null,
            'brand'            => $product['brand'] ?? null,
            'price'            => $product['price'] ?? null,
            'currency'         => $product['currency'] ?? 'EUR',
            'merchant'         => $product['merchant'] ?? null,
            'product_url'      => $product['product_url'] ?? null,
            'image_url'        => $product['image_url'] ?? null,
            'in_stock'         => $product['in_stock'] ?? null,
            'final_score'      => $product['final_score'] ?? null,
            'rank_label'       => $product['rank_label'] ?? null,
            'similarity_score' => $product['similarity_score'] ?? null,
            'source'           => $product['source'] ?? null,
        ], array_values($results));
    }
}
