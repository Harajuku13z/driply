<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Outfit */
class OutfitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price !== null ? (float) $this->price : null,
            'currency' => $this->currency,
            'tags' => $this->tags ?? [],
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'images' => OutfitImageResource::collection($this->whenLoaded('images')),
            'tag_refs' => TagResource::collection($this->whenLoaded('tagModels')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
