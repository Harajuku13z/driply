<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Outfit;
use App\Models\User;

class OutfitPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Outfit $outfit): bool
    {
        return (int) $outfit->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Outfit $outfit): bool
    {
        return (int) $outfit->user_id === (int) $user->id;
    }

    public function delete(User $user, Outfit $outfit): bool
    {
        return (int) $outfit->user_id === (int) $user->id;
    }
}
