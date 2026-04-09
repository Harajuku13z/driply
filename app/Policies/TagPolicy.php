<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function delete(User $user, Tag $tag): bool
    {
        return $tag->isOwnedBy((int) $user->id);
    }
}
