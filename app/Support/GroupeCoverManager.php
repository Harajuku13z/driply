<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Groupe;
use App\Models\GroupeItem;
use App\Models\Inspiration;
use Illuminate\Support\Facades\DB;

final class GroupeCoverManager
{
    public static function refreshCoverIfNeeded(Groupe $groupe): void
    {
        $groupe->loadMissing(['groupeItems.inspiration']);

        $firstThumb = self::firstThumbnailFromItems($groupe);
        if ($firstThumb === null) {
            $groupe->cover_image = null;
            $groupe->saveQuietly();

            return;
        }

        if ($groupe->cover_image === null || $groupe->cover_image === '') {
            $groupe->cover_image = $firstThumb;
            $groupe->saveQuietly();

            return;
        }

        $stillPresent = false;
        foreach ($groupe->groupeItems as $gi) {
            $insp = $gi->inspiration;
            if ($insp && (string) $insp->thumbnail_url === (string) $groupe->cover_image) {
                $stillPresent = true;
                break;
            }
        }

        if (! $stillPresent) {
            $groupe->cover_image = $firstThumb;
            $groupe->saveQuietly();
        }
    }

    public static function afterDetach(Groupe $groupe): void
    {
        self::refreshCoverIfNeeded($groupe);
    }

    private static function firstThumbnailFromItems(Groupe $groupe): ?string
    {
        $items = $groupe->groupeItems->sortBy('position')->values();
        foreach ($items as $gi) {
            $t = $gi->inspiration?->thumbnail_url;
            if (is_string($t) && $t !== '') {
                return $t;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pivot
     */
    public static function attachInspiration(Groupe $groupe, Inspiration $inspiration, array $pivot = []): GroupeItem
    {
        $max = (int) DB::table('groupe_items')->where('groupe_id', $groupe->id)->max('position');
        $position = (int) ($pivot['position'] ?? ($max + 1));

        $item = GroupeItem::query()->create([
            'groupe_id' => $groupe->id,
            'inspiration_id' => $inspiration->id,
            'position' => $position,
            'note' => $pivot['note'] ?? null,
            'added_at' => now(),
        ]);

        if ($groupe->cover_image === null || $groupe->cover_image === '') {
            $thumb = $inspiration->thumbnail_url;
            if (is_string($thumb) && $thumb !== '') {
                $groupe->cover_image = $thumb;
                $groupe->saveQuietly();
            }
        }

        return $item;
    }
}
