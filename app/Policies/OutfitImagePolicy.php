<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OutfitImage;
use App\Models\User;

class OutfitImagePolicy
{
    public function update(User $user, OutfitImage $image): bool
    {
        $outfit = $image->outfit;

        return $outfit !== null && (int) $outfit->user_id === (int) $user->id;
    }

    public function delete(User $user, OutfitImage $image): bool
    {
        return $this->update($user, $image);
    }
}
