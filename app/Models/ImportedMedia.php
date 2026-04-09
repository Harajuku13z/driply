<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MediaPlatform;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, string>|null $frames
 */
class ImportedMedia extends Model
{
    use HasUuids;

    protected $table = 'imported_media';

    protected $fillable = [
        'user_id',
        'outfit_id',
        'platform',
        'source_url',
        'local_path',
        'thumbnail_url',
        'title',
        'duration_seconds',
        'type',
        'status',
        'frames',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frames' => 'array',
            'platform' => MediaPlatform::class,
            'type' => MediaType::class,
            'status' => MediaStatus::class,
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
