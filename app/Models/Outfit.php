<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutfitStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, string>|null $tags
 * @property array<string, mixed>|null $image_search_cache
 */
class Outfit extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'price',
        'currency',
        'tags',
        'status',
        'image_search_cache',
        'image_search_cache_query',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'image_search_cache' => 'array',
            'price' => 'decimal:2',
            'status' => OutfitStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OutfitImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(OutfitImage::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tagModels(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'outfit_tag', 'outfit_id', 'tag_id')
            ->withTimestamps();
    }
}
