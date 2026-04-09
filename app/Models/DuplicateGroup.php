<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, string> $image_ids
 */
class DuplicateGroup extends Model
{
    use HasUuids;

    protected $fillable = [
        'scan_session_id',
        'image_ids',
        'similarity_score',
        'resolved',
        'resolution_action',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'image_ids' => 'array',
            'resolved' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ScanSession, $this>
     */
    public function scanSession(): BelongsTo
    {
        return $this->belongsTo(ScanSession::class, 'scan_session_id');
    }
}
