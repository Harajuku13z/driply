<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Outfit, $this>
     */
    public function outfits(): BelongsToMany
    {
        return $this->belongsToMany(Outfit::class, 'outfit_tag', 'tag_id', 'outfit_id')
            ->withTimestamps();
    }

    public function isOwnedBy(?int $userId): bool
    {
        return $this->user_id !== null && $userId !== null && (int) $this->user_id === $userId;
    }

    public function isGlobal(): bool
    {
        return $this->user_id === null;
    }
}
