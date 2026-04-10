<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;

/** @mixin \App\Models\Groupe */
class GroupeDetailResource extends GroupeResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        $base['inspirations'] = InspirationResource::collection(
            $this->whenLoaded('inspirations', fn () => $this->inspirations, collect())
        );

        return $base;
    }
}
