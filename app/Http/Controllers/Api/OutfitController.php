<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OutfitStatus;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Outfit\StoreOutfitRequest;
use App\Http\Requests\Outfit\UpdateOutfitRequest;
use App\Http\Resources\OutfitResource;
use App\Models\Outfit;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OutfitController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = $request->user()
            ->outfits()
            ->with(['images', 'tagModels']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('tag')) {
            $needle = $request->string('tag')->toString();
            $slug = Str::slug($needle);
            $query->where(function ($q) use ($needle, $slug) {
                $q->whereJsonContains('tags', $needle)
                    ->orWhereHas('tagModels', function ($t) use ($needle, $slug) {
                        $t->where('slug', $slug)->orWhere('name', $needle);
                    });
            });
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->query('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->query('max_price'));
        }

        $sortField = $request->query('sort_by', 'created_at');
        $sortDir = $request->query('sort_dir', 'desc') === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortField, ['created_at', 'price', 'title'], true)) {
            $sortField = 'created_at';
        }

        $query->orderBy($sortField, $sortDir);

        $paginator = $query->paginate($perPage);

        return $this->paginated($paginator, fn (Outfit $o) => (new OutfitResource($o))->resolve());
    }

    public function store(StoreOutfitRequest $request): JsonResponse
    {
        $data = $request->validated();
        /** @var \App\Models\User $user */
        $user = $request->user();

        $outfit = $user->outfits()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? null,
            'currency' => $data['currency'] ?? $user->currency_preference,
            'tags' => $data['tags'] ?? [],
            'status' => $data['status'] ?? OutfitStatus::Draft,
        ]);

        $this->syncPivotTags($outfit, $data['tags'] ?? []);
        $user->increment('outfits_count');

        $outfit->load(['images', 'tagModels']);

        return $this->created((new OutfitResource($outfit))->resolve(), 'Outfit created');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $outfit = Outfit::query()
            ->with(['images', 'tagModels'])
            ->findOrFail($id);

        if (! $request->user()?->can('view', $outfit)) {
            abort(403);
        }

        return $this->success((new OutfitResource($outfit))->resolve());
    }

    public function update(UpdateOutfitRequest $request, string $id): JsonResponse
    {
        $outfit = Outfit::query()->findOrFail($id);
        if (! $request->user()?->can('update', $outfit)) {
            abort(403);
        }

        $data = $request->validated();

        if (array_key_exists('title', $data)) {
            $outfit->title = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $outfit->description = $data['description'];
        }
        if (array_key_exists('price', $data)) {
            $outfit->price = $data['price'];
        }
        if (array_key_exists('currency', $data)) {
            $outfit->currency = $data['currency'];
        }
        if (array_key_exists('tags', $data)) {
            $outfit->tags = $data['tags'];
            $this->syncPivotTags($outfit, $data['tags'] ?? []);
        }
        if (array_key_exists('status', $data)) {
            $outfit->status = $data['status'];
        }

        $outfit->save();
        $outfit->load(['images', 'tagModels']);

        return $this->success((new OutfitResource($outfit))->resolve(), 'Outfit updated');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $outfit = Outfit::query()->findOrFail($id);
        if (! $request->user()?->can('delete', $outfit)) {
            abort(403);
        }

        $outfit->delete();
        $request->user()?->decrement('outfits_count');

        return $this->success(null, 'Outfit deleted');
    }

    public function similar(Request $request, string $id): JsonResponse
    {
        $base = Outfit::query()->with('tagModels')->findOrFail($id);
        if (! $request->user()?->can('view', $base)) {
            abort(403);
        }

        $tags = $base->tags ?? [];
        $tagSlugs = $base->tagModels->pluck('slug')->all();

        $candidates = $request->user()
            ->outfits()
            ->where('id', '!=', $base->id)
            ->with(['images', 'tagModels'])
            ->get();

        $scored = $candidates->map(function (Outfit $o) use ($base, $tags, $tagSlugs) {
            $score = 0.0;
            $otherTags = $o->tags ?? [];
            $intersect = array_intersect($tags, $otherTags);
            $score += count($intersect) * 2;

            $otherSlugs = $o->tagModels->pluck('slug')->all();
            $score += count(array_intersect($tagSlugs, $otherSlugs)) * 3;

            $descA = (string) ($base->description ?? '');
            $descB = (string) ($o->description ?? '');
            if ($descA !== '' && $descB !== '') {
                similar_text($descA, $descB, $pct);
                $score += ($pct / 100) * 5;
            }

            return ['outfit' => $o, 'score' => $score];
        })->sortByDesc('score')->values();

        $top = $scored->take(10)->pluck('outfit');

        return $this->success(
            $top->map(fn (Outfit $o) => (new OutfitResource($o))->resolve())->values()->all(),
            '',
            ['similarity' => ['limit' => 10]]
        );
    }

    /**
     * @param  list<string>  $tagNames
     */
    private function syncPivotTags(Outfit $outfit, array $tagNames): void
    {
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
    }
}
