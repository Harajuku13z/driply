<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImportedMedia;
use App\Models\User;

class ImportedMediaPolicy
{
    public function view(User $user, ImportedMedia $media): bool
    {
        return (int) $media->user_id === (int) $user->id;
    }

    public function delete(User $user, ImportedMedia $media): bool
    {
        return $this->view($user, $media);
    }

    public function update(User $user, ImportedMedia $media): bool
    {
        return $this->view($user, $media);
    }
}
