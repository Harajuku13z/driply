<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, array<string, mixed>>|null $lens_products
 * @property array<string, mixed>|null $price_analysis
 */
class LensResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'outfit_id',
        'input_image_url',
        'lens_products',
        'price_analysis',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lens_products' => 'array',
            'price_analysis' => 'array',
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
     * @return BelongsTo<Outfit, $this>
     */
    public function outfit(): BelongsTo
    {
        return $this->belongsTo(Outfit::class);
    }
}
