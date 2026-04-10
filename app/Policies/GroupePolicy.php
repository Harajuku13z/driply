<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Groupe;
use App\Models\User;

class GroupePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Groupe $groupe): bool
    {
        return $user->id === $groupe->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Groupe $groupe): bool
    {
        return $user->id === $groupe->user_id;
    }

    public function delete(User $user, Groupe $groupe): bool
    {
        return $user->id === $groupe->user_id;
    }
}
