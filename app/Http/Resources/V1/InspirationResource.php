<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Inspiration */
class InspirationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type?->value ?? 'other';

        $base = [
            'id' => $this->id,
            'type' => $type,
            'title' => $this->title,
            'thumbnail_url' => $this->resolvedListThumbnailUrl(),
            'note' => $this->note,
            'tags' => $this->tags ?? [],
            'is_favorite' => (bool) $this->is_favorite,
            'status' => $this->status?->value ?? 'processed',
            'created_at' => $this->created_at?->toIso8601String(),
            'groupe_ids' => $this->whenLoaded('groupes', fn () => $this->groupes->pluck('id')->values()->all(), []),
            'pivot' => $this->when($this->pivot !== null, function () {
                return [
                    'position' => (int) ($this->pivot->position ?? 0),
                    'note' => $this->pivot->note,
                    'added_at' => isset($this->pivot->added_at) && $this->pivot->added_at
                        ? $this->pivot->added_at->toIso8601String()
                        : null,
                ];
            }),
        ];

        if ($type === 'scan') {
            $base['scan_item_type'] = $this->scan_item_type;
            $base['scan_brand'] = $this->scan_brand;
            $base['scan_color'] = $this->scan_color;
            $base['scan_results'] = $this->scan_results;
            $base['scan_price_summary'] = $this->scan_price_summary;
            $base['scan_query'] = $this->scan_query;
        }

        if (in_array($type, ['tiktok', 'instagram', 'youtube', 'other', 'photo'], true)) {
            $base['source_url'] = $this->source_url;
            $base['platform'] = $this->platform;
            $base['media_url'] = $this->media_url;
            $base['media_type'] = $this->media_type?->value;
            $base['duration_seconds'] = $this->duration_seconds;
            // Images supplémentaires (carrousel) — tableau vide si absent
            $base['additional_images'] = $this->additional_images ?? [];
            // Favicon de secours quand thumbnail_url et media_url sont vides
            $base['favicon_url'] = $this->favicon_url;
        }

        return $base;
    }
}
