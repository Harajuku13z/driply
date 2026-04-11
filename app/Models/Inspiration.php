<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InspirationStatusEnum;
use App\Enums\InspirationTypeEnum;
use App\Enums\MediaTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inspiration extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'scan_query',
        'scan_item_type',
        'scan_brand',
        'scan_color',
        'scan_results',
        'scan_price_summary',
        'source_url',
        'platform',
        'media_url',
        'thumbnail_url',
        'title',
        'duration_seconds',
        'media_type',
        'note',
        'is_favorite',
        'tags',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InspirationTypeEnum::class,
            'media_type' => MediaTypeEnum::class,
            'status' => InspirationStatusEnum::class,
            'scan_results' => 'array',
            'scan_price_summary' => 'array',
            'tags' => 'array',
            'is_favorite' => 'boolean',
            'duration_seconds' => 'integer',
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
        return $this->hasMany(GroupeItem::class, 'inspiration_id');
    }

    /**
     * @return BelongsToMany<Groupe, $this, GroupeItem>
     */
    public function groupes(): BelongsToMany
    {
        return $this->belongsToMany(Groupe::class, 'groupe_items', 'inspiration_id', 'groupe_id')
            ->using(GroupeItem::class)
            ->withPivot(['id', 'position', 'note', 'added_at'])
            ->orderByPivot('position');
    }

    /**
     * URL affichable en liste / grille : `thumbnail_url` colonne, sinon `media_url`, sinon première image dans `scan_results`.
     */
    public function resolvedListThumbnailUrl(): ?string
    {
        $thumb = trim((string) ($this->thumbnail_url ?? ''));
        if ($thumb !== '') {
            return $this->thumbnail_url;
        }

        $media = trim((string) ($this->media_url ?? ''));
        if ($media !== '') {
            return $this->media_url;
        }

        $results = $this->scan_results;
        if (! is_array($results)) {
            return null;
        }

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['thumbnail_url', 'thumbnail', 'image_url', 'image'] as $key) {
                $candidate = trim((string) ($row[$key] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
