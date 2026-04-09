<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\OutfitResource;
use App\Http\Resources\TagResource;
use App\Models\Outfit;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TagController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = Tag::query()
            ->where(function ($q) use ($request) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $request->user()->id);
            })
            ->orderBy('name');

        $paginator = $query->paginate($perPage);

        return $this->paginated($paginator, fn (Tag $t) => (new TagResource($t))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $slug = Str::slug($data['name']);

        $exists = Tag::query()
            ->where('slug', $slug)
            ->where('user_id', $request->user()->id)
            ->exists();

        if ($exists) {
            return $this->error('Tag already exists', 422, ['name' => ['Duplicate']]);
        }

        $tag = Tag::query()->create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'slug' => $slug,
        ]);

        return $this->created((new TagResource($tag))->resolve(), 'Tag created');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tag = Tag::query()->findOrFail($id);

        if (! $request->user()?->can('delete', $tag)) {
            abort(403);
        }

        $tag->delete();

        return $this->success(null, 'Tag deleted');
    }

    public function attachToOutfit(Request $request, string $outfitId): JsonResponse
    {
        $outfit = Outfit::query()->findOrFail($outfitId);
        if (! $request->user()?->can('update', $outfit)) {
            abort(403);
        }

        $data = $request->validate([
            'tags' => ['required', 'array', 'min:1'],
            'tags.*' => ['string', 'max:120'],
        ]);

        $tagNames = $data['tags'];
        $ids = [];
        foreach ($tagNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $slug = Str::slug($name);

            $tag = Tag::query()
                ->where('slug', $slug)
                ->where(function ($q) use ($outfit) {
                    $q->whereNull('user_id')->orWhere('user_id', $outfit->user_id);
                })
                ->first();

            if ($tag === null) {
                $tag = Tag::query()->create([
                    'user_id' => $outfit->user_id,
                    'name' => $name,
                    'slug' => $slug,
                ]);
            }

            $ids[] = $tag->id;
        }

        $outfit->tagModels()->sync($ids);
        $outfit->tags = array_values(array_map('strval', $tagNames));
        $outfit->save();

        $outfit->load(['images', 'tagModels']);

        return $this->success((new OutfitResource($outfit))->resolve(), 'Tags synced');
    }

    public function detachFromOutfit(Request $request, string $outfitId, string $tagId): JsonResponse
    {
        $outfit = Outfit::query()->findOrFail($outfitId);
        if (! $request->user()?->can('update', $outfit)) {
            abort(403);
        }

        $tag = Tag::query()->findOrFail($tagId);
        $outfit->tagModels()->detach($tag->id);

        $names = $outfit->tagModels()->pluck('name')->all();
        $outfit->tags = $names;
        $outfit->save();

        $outfit->load(['images', 'tagModels']);

        return $this->success((new OutfitResource($outfit))->resolve(), 'Tag detached');
    }
}
