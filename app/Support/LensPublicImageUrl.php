<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * URL publique des images pour SerpAPI (Lens) : préfère /driply-public/lens/… et /driply-public/scans/…
 * pour éviter 403 sur /storage/ (symlink manquant sur l’hébergeur). Les uploads scan API utilisent `scans/`.
 */
final class LensPublicImageUrl
{
    public static function absoluteFromPublicDiskPath(string $relativeOrAbsolute): string
    {
        if (str_starts_with($relativeOrAbsolute, 'http://') || str_starts_with($relativeOrAbsolute, 'https://')) {
            return $relativeOrAbsolute;
        }

        $relative = ltrim($relativeOrAbsolute, '/');

        if (self::useRoute() && (str_starts_with($relative, 'lens/') || str_starts_with($relative, 'scans/') || str_starts_with($relative, 'imports/'))) {
            return url('/driply-public/'.$relative);
        }

        $base = rtrim((string) config('driply.lens.public_storage_base_url', ''), '/');
        if ($base !== '') {
            return $base.'/storage/'.$relative;
        }

        $diskUrl = Storage::disk('public')->url($relative);
        if (str_starts_with($diskUrl, 'http://') || str_starts_with($diskUrl, 'https://')) {
            return $diskUrl;
        }

        return url($diskUrl);
    }

    private static function useRoute(): bool
    {
        return (bool) config('driply.lens.use_public_file_route', true);
    }
}
