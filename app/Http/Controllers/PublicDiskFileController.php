<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Sert les fichiers du disque `public` via PHP (sans lien symbolique public/storage).
 * Utilisé pour les images Lens envoyées à SerpAPI lorsque /storage/… n’est pas joignable.
 */
class PublicDiskFileController extends Controller
{
    /**
     * GET /driply-public/{path}  path autorisé : lens/… uniquement
     */
    public function show(string $path): SymfonyResponse
    {
        $path = trim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            abort(404);
        }
        if (! str_starts_with($path, 'lens/')) {
            abort(404);
        }

        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
