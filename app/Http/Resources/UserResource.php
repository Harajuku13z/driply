<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $avatarUrl = null;
        if ($this->avatar !== null && $this->avatar !== '') {
            $avatarUrl = Storage::disk('public')->url($this->avatar);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $avatarUrl,
            'plan' => $this->plan instanceof \BackedEnum ? $this->plan->value : $this->plan,
            'currency_preference' => $this->currency_preference,
            'outfits_count' => (int) $this->outfits_count,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
