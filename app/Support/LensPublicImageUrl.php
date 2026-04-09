<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * URL publique des scans Lens : préfère /driply-public/lens/… pour éviter 403 sur /storage/ (symlink, hébergeur).
 */
final class LensPublicImageUrl
{
    public static function absoluteFromPublicDiskPath(string $relativeOrAbsolute): string
    {
        if (str_starts_with($relativeOrAbsolute, 'http://') || str_starts_with($relativeOrAbsolute, 'https://')) {
            return $relativeOrAbsolute;
        }

        $relative = ltrim($relativeOrAbsolute, '/');

        if (self::useRoute() && str_starts_with($relative, 'lens/')) {
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
