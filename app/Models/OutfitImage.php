<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutfitImageSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutfitImage extends Model
{
    use HasUuids;

    protected $fillable = [
        'outfit_id',
        'url',
        'source',
        'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'source' => OutfitImageSource::class,
        ];
    }

    /**
     * @return BelongsTo<Outfit, $this>
     */
    public function outfit(): BelongsTo
    {
        return $this->belongsTo(Outfit::class);
    }
}
