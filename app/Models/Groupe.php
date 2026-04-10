<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Groupe extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'groupes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'cover_image',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
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
     * @return HasMany<GroupeItem, $this>
     */
    public function groupeItems(): HasMany
    {
        return $this->hasMany(GroupeItem::class, 'groupe_id');
    }

    /**
     * @return BelongsToMany<Inspiration, $this, GroupeItem>
     */
    public function inspirations(): BelongsToMany
    {
        return $this->belongsToMany(Inspiration::class, 'groupe_items', 'groupe_id', 'inspiration_id')
            ->using(GroupeItem::class)
            ->withPivot(['id', 'position', 'note', 'added_at'])
            ->orderByPivot('position');
    }
}
