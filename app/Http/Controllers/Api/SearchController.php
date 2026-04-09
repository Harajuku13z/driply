<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OutfitImageSource;
use App\Exceptions\ExternalServiceException;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\ImageSearchRequest;
use App\Models\Outfit;
use App\Models\OutfitImage;
use App\Services\SerpApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SearchController extends Controller
{
    use ApiResponses;

    public function images(ImageSearchRequest $request, SerpApiService $serpApi): JsonResponse
    {
        try {
            $results = $serpApi->searchImages($request->validated('query'));
        } catch (ExternalServiceException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_GATEWAY);
        }

        if ($request->filled('outfit_id')) {
            $outfit = Outfit::query()->find($request->validated('outfit_id'));
            if ($outfit !== null && $request->user()?->can('update', $outfit)) {
                $outfit->image_search_cache = ['results' => $results, 'cached_at' => now()->toIso8601String()];
                $outfit->image_search_cache_query = $request->validated('query');
                $outfit->save();
            }
        }

        return $this->success(['images' => $results], 'Search complete');
    }

    public function attach(Request $request, SerpApiService $serpApi): JsonResponse
    {
        $data = $request->validate([
            'outfit_id' => ['required', 'uuid', Rule::exists('outfits', 'id')->where('user_id', (int) $request->user()?->id)],
            'image_url' => ['required', 'url'],
            'title' => ['nullable', 'string', 'max:500'],
        ]);

        $outfit = Outfit::query()->findOrFail($data['outfit_id']);
        if (! $request->user()?->can('update', $outfit)) {
            abort(403);
        }

        try {
            $path = $serpApi->downloadAndStore($data['image_url'], 'public');
        } catch (ExternalServiceException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_GATEWAY);
        }

        $hasPrimary = $outfit->images()->where('is_primary', true)->exists();

        $image = $outfit->images()->create([
            'url' => $path,
            'source' => OutfitImageSource::Serpapi,
            'is_primary' => ! $hasPrimary,
        ]);

        return $this->created([
            'image_id' => $image->id,
            'local_path' => $path,
            'public_url' => Storage::disk('public')->url($path),
        ], 'Image attached');
    }
}
