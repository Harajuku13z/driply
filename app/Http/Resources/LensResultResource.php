<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LensResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin LensResult */
class LensResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $input = $this->input_image_url;
        if ($input !== '' && ! str_starts_with($input, 'http://') && ! str_starts_with($input, 'https://')) {
            $input = Storage::disk('public')->url($input);
        }

        $products = $this->lens_products ?? [];
        $analysis = is_array($this->price_analysis) ? $this->price_analysis : [];

        return [
            'id' => $this->id,
            'outfit_id' => $this->outfit_id,
            'input_image_url' => $input,
            'all_products' => $products,
            'lens_products' => $products,
            'top_3' => $analysis['top_3_picks'] ?? [],
            'price_analysis' => $analysis !== [] ? $analysis : (object) [],
            'currency' => $this->currency,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
