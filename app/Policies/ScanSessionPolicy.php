<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ScanSession;
use App\Models\User;

class ScanSessionPolicy
{
    public function view(User $user, ScanSession $session): bool
    {
        return (int) $session->user_id === (int) $user->id;
    }
}
