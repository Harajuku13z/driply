<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LensResult;
use App\Models\User;

class LensResultPolicy
{
    public function view(User $user, LensResult $result): bool
    {
        return (int) $result->user_id === (int) $user->id;
    }

    public function update(User $user, LensResult $result): bool
    {
        return $this->view($user, $result);
    }
}
