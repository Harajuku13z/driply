<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin \App\Models\ImportedMedia */
class ImportedMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $localUrl = null;
        if ($this->local_path !== null && $this->local_path !== '') {
            $localUrl = URL::temporarySignedRoute('media.signed', now()->addHour(), ['id' => $this->id]);
        }

        /** @var array<int, string>|null $frames */
        $frames = $this->frames;
        $frameUrls = [];
        if (is_array($frames)) {
            foreach ($frames as $idx => $path) {
                $frameUrls[] = URL::temporarySignedRoute(
                    'media.frame',
                    now()->addHour(),
                    ['id' => $this->id, 'index' => $idx]
                );
            }
        }

        return [
            'id' => $this->id,
            'outfit_id' => $this->outfit_id,
            'platform' => $this->platform instanceof \BackedEnum ? $this->platform->value : $this->platform,
            'source_url' => $this->source_url,
            'local_url' => $localUrl,
            'thumbnail_url' => $this->thumbnail_url,
            'title' => $this->title,
            'duration_seconds' => $this->duration_seconds,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'frame_urls' => $frameUrls,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
