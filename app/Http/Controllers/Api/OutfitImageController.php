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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class OutfitImageController extends Controller
{
    use ApiResponses;

    public function store(Request $request, string $outfitId): JsonResponse
    {
        $outfit = Outfit::query()->findOrFail($outfitId);
        if (! $request->user()?->can('update', $outfit)) {
            abort(403);
        }

        $hasFileUpload = $request->hasFile('images');
        $hasUrl = $request->filled('image_url');

        if ($hasUrl && $hasFileUpload) {
            return $this->error('Envoyer soit image_url soit images, pas les deux.', 422);
        }

        if (! $hasUrl && ! $hasFileUpload) {
            $request->validate([
                'images' => ['required', 'array', 'min:1'],
            ]);
        }

        $hasPrimary = $outfit->images()->where('is_primary', true)->exists();
        $created = [];

        if ($hasUrl) {
            $data = $request->validate([
                'image_url' => ['required', 'url', 'max:2048'],
                'source' => ['nullable', 'string', Rule::enum(OutfitImageSource::class)],
                'title' => ['nullable', 'string', 'max:500'],
                'buy_link' => ['nullable', 'url', 'max:2048'],
                'price_found' => ['nullable', 'numeric', 'min:0'],
            ]);

            $remote = trim((string) $data['image_url']);
            try {
                $binary = Http::timeout(60)
                    ->withHeaders(['User-Agent' => 'Driply/1.0'])
                    ->get($remote)
                    ->throw()
                    ->body();
            } catch (Throwable $e) {
                return $this->error('Impossible de télécharger l’image : '.$e->getMessage(), 422);
            }

            if ($binary === '') {
                return $this->error('Image distante vide.', 422);
            }

            $path = 'outfits/'.Str::uuid()->toString().'.jpg';
            Storage::disk('public')->put($path, $binary);

            $source = isset($data['source'])
                ? OutfitImageSource::from($data['source'])
                : OutfitImageSource::GoogleLens;

            $created[] = $outfit->images()->create([
                'url' => $path,
                'source' => $source,
                'is_primary' => ! $hasPrimary,
                'title' => $data['title'] ?? null,
                'buy_link' => $data['buy_link'] ?? null,
                'price_found' => isset($data['price_found']) ? (float) $data['price_found'] : null,
            ]);
        } else {
            $request->validate([
                'images' => ['required', 'array', 'min:1'],
                'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            ]);

            foreach ($request->file('images', []) as $idx => $file) {
                $path = 'outfits/'.Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
                Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

                $created[] = $outfit->images()->create([
                    'url' => $path,
                    'source' => OutfitImageSource::Upload,
                    'is_primary' => ! $hasPrimary && $idx === 0,
                ]);
            }
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
