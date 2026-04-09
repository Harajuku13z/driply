<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ImportedMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignedMediaController extends Controller
{
    public function show(Request $request, string $id): StreamedResponse|\Illuminate\Http\Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired URL');
        }

        $media = ImportedMedia::query()->findOrFail($id);

        if ($media->local_path === null || $media->local_path === '') {
            abort(404);
        }

        return Storage::disk('media')->response($media->local_path);
    }

    public function frame(Request $request, string $id, int $index): StreamedResponse|\Illuminate\Http\Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired URL');
        }

        $media = ImportedMedia::query()->findOrFail($id);
        $frames = is_array($media->frames) ? $media->frames : [];

        if (! isset($frames[$index])) {
            abort(404);
        }

        return Storage::disk('media')->response((string) $frames[$index]);
    }
}
