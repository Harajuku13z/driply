<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\OutfitImage */
class OutfitImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $url = $this->url;
        if ($url !== '' && ! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = Storage::disk('public')->url($url);
        }

        return [
            'id' => $this->id,
            'url' => $url,
            'source' => $this->source instanceof \BackedEnum ? $this->source->value : $this->source,
            'is_primary' => (bool) $this->is_primary,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
