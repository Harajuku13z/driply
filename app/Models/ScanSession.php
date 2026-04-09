<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScanSessionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'status',
        'total_images',
        'duplicates_found',
        'processed_images',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScanSessionStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return HasMany<DuplicateGroup, $this>
     */
    public function duplicateGroups(): HasMany
    {
        return $this->hasMany(DuplicateGroup::class, 'scan_session_id');
    }
}
