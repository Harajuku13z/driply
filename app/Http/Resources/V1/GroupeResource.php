<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Groupe */
class GroupeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $thumbs = [];
        if ($this->relationLoaded('inspirations')) {
            $thumbs = $this->inspirations
                ->sortBy(fn ($i) => $i->pivot->position ?? 0)
                ->take(4)
                ->map(function ($i) {
                    $u = $i->resolvedListThumbnailUrl();

                    return is_string($u) ? $u : (is_scalar($u) ? (string) $u : null);
                })
                ->filter(static fn ($v) => is_string($v) && $v !== '')
                ->values()
                ->all();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'cover_image' => $this->cover_image,
            'position' => (int) $this->position,
            'inspirations_count' => (int) ($this->resource->inspirations_count ?? 0),
            'preview_thumbnails' => $thumbs,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
