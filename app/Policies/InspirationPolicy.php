<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inspiration;
use App\Models\User;

class InspirationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Inspiration $inspiration): bool
    {
        return $user->id === $inspiration->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Inspiration $inspiration): bool
    {
        return $user->id === $inspiration->user_id;
    }

    public function delete(User $user, Inspiration $inspiration): bool
    {
        return $user->id === $inspiration->user_id;
    }
}
