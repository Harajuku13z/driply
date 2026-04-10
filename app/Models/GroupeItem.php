<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class GroupeItem extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'groupe_items';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'groupe_id',
        'inspiration_id',
        'position',
        'note',
        'added_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'added_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Groupe, $this>
     */
    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_id');
    }

    /**
     * @return BelongsTo<Inspiration, $this>
     */
    public function inspiration(): BelongsTo
    {
        return $this->belongsTo(Inspiration::class, 'inspiration_id');
    }
}
