<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OutfitImageSource;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\OutfitImageResource;
use App\Http\Resources\OutfitResource;
use App\Models\Outfit;
use App\Models\OutfitImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OutfitImageController extends Controller
{
    use ApiResponses;

    public function store(Request $request, string $outfitId): JsonResponse
    {
        $outfit = Outfit::query()->findOrFail($outfitId);
        if (! $request->user()?->can('update', $outfit)) {
            abort(403);
        }

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $hasPrimary = $outfit->images()->where('is_primary', true)->exists();

        $created = [];
        foreach ($request->file('images', []) as $idx => $file) {
            $path = 'outfits/'.Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
            Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

            $created[] = $outfit->images()->create([
                'url' => $path,
                'source' => OutfitImageSource::Upload,
                'is_primary' => ! $hasPrimary && $idx === 0,
            ]);
        }

        $outfit->load(['images', 'tagModels']);

        return $this->created([
            'images' => array_map(fn (OutfitImage $img) => (new OutfitImageResource($img))->resolve(), $created),
            'outfit' => (new OutfitResource($outfit))->resolve(),
        ], 'Images uploaded');
    }

    public function destroy(Request $request, string $outfitId, string $imageId): JsonResponse
    {
        $outfit = Outfit::query()->findOrFail($outfitId);
        $image = OutfitImage::query()->where('outfit_id', $outfit->id)->findOrFail($imageId);

        if (! $request->user()?->can('delete', $image)) {
            abort(403);
        }

        if (! str_starts_with($image->url, 'http://') && ! str_starts_with($image->url, 'https://')) {
            Storage::disk('public')->delete($image->url);
        }

        $wasPrimary = $image->is_primary;
        $image->delete();

        if ($wasPrimary) {
            $next = $outfit->images()->first();
            if ($next !== null) {
                $next->is_primary = true;
                $next->save();
            }
        }

        return $this->success(null, 'Image deleted');
    }

    public function setPrimary(Request $request, string $outfitId, string $imageId): JsonResponse
    {
        $outfit = Outfit::query()->findOrFail($outfitId);
        $image = OutfitImage::query()->where('outfit_id', $outfit->id)->findOrFail($imageId);

        if (! $request->user()?->can('update', $image)) {
            abort(403);
        }

        $outfit->images()->update(['is_primary' => false]);
        $image->is_primary = true;
        $image->save();

        return $this->success((new OutfitImageResource($image))->resolve(), 'Primary image updated');
    }
}
