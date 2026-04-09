<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LensResult;
use App\Support\LensPublicImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LensResult */
class LensResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $input = (string) ($this->input_image_url ?? '');
        if ($input !== '' && ! str_starts_with($input, 'http://') && ! str_starts_with($input, 'https://')) {
            $input = LensPublicImageUrl::absoluteFromPublicDiskPath($input);
        }

        $products = $this->lens_products ?? [];
        $analysis = is_array($this->price_analysis) ? $this->price_analysis : [];

        return [
            'id' => $this->id,
            'outfit_id' => $this->outfit_id,
            'input_image_url' => $input,
            'query_used' => $analysis['search_query_used'] ?? null,
            'item_detected' => $analysis['item_identified'] ?? null,
            'brand' => $analysis['brand'] ?? null,
            'color' => $analysis['color'] ?? null,
            'price_summary' => $analysis['price_summary'] ?? null,
            'top_results' => $analysis['top_results'] ?? [],
            'all_products' => $products,
            'lens_products' => $products,
            'top_3' => $analysis['top_3_picks'] ?? [],
            'price_analysis' => $analysis !== [] ? $analysis : (object) [],
            'currency' => $this->currency,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
