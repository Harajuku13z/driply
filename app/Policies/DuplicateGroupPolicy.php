<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DuplicateGroup;
use App\Models\User;

class DuplicateGroupPolicy
{
    public function resolve(User $user, DuplicateGroup $group): bool
    {
        $session = $group->scanSession;

        return $session !== null && (int) $session->user_id === (int) $user->id;
    }
}
