<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GroupeItem */
class GroupeItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'groupe_id' => $this->groupe_id,
            'inspiration_id' => $this->inspiration_id,
            'position' => (int) $this->position,
            'note' => $this->note,
            'added_at' => $this->added_at?->toIso8601String(),
        ];
    }
}
