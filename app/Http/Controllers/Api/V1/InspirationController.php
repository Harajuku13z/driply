<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\InspirationStatusEnum;
use App\Enums\InspirationTypeEnum;
use App\Enums\MediaTypeEnum;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InspirationResource;
use App\Jobs\ProcessImportedMediaJob;
use App\Models\GroupeItem;
use App\Models\Inspiration;
use App\Services\FastServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InspirationController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = Inspiration::query()->where('user_id', $user->id)->with('groupes');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->boolean('favorite')) {
            $query->where('is_favorite', true);
        }

        if ($request->filled('groupe_id')) {
            $gid = $request->string('groupe_id')->toString();
            $query->whereHas('groupes', fn ($q) => $q->where('groupes.id', $gid));
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, fn (Inspiration $i) => (new InspirationResource($i))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'type' => ['required', 'string', 'in:scan,photo,tiktok,instagram,youtube,other'],
            'title' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'platform' => ['nullable', 'string', 'max:32'],
            'media_type' => ['nullable', 'string', 'in:image,video'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $inspiration = Inspiration::query()->create([
            'user_id' => $user->id,
            'type' => InspirationTypeEnum::from($data['type']),
            'title' => $data['title'] ?? null,
            'note' => $data['note'] ?? null,
            'tags' => $data['tags'] ?? null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'source_url' => $data['source_url'] ?? null,
            'platform' => $data['platform'] ?? null,
            'media_type' => isset($data['media_type']) ? MediaTypeEnum::from($data['media_type']) : null,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'status' => InspirationStatusEnum::Processed,
        ]);

        $inspiration->load('groupes');

        return $this->created((new InspirationResource($inspiration))->resolve(), 'Inspiration créée');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $inspiration = Inspiration::query()
            ->where('user_id', $user->id)
            ->with('groupes')
            ->findOrFail($id);

        return $this->success((new InspirationResource($inspiration))->resolve());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $inspiration = Inspiration::query()->where('user_id', $user->id)->findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'media_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $inspiration->fill($data);
        $inspiration->save();
        $inspiration->load('groupes');

        return $this->success((new InspirationResource($inspiration))->resolve(), 'Inspiration mise à jour');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $inspiration = Inspiration::query()->where('user_id', $user->id)->findOrFail($id);
        GroupeItem::query()->where('inspiration_id', $inspiration->id)->delete();
        $inspiration->delete();

        return $this->success(null, 'Inspiration supprimée');
    }

    public function toggleFavorite(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $inspiration = Inspiration::query()->where('user_id', $user->id)->findOrFail($id);
        $inspiration->is_favorite = ! $inspiration->is_favorite;
        $inspiration->save();
        $inspiration->load('groupes');

        return $this->success((new InspirationResource($inspiration))->resolve());
    }

    public function import(Request $request, FastServerService $fastServer): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'platform' => ['required', 'string', 'in:tiktok,instagram,youtube,other'],
        ]);

        $fetch = $fastServer->fetchMedia($data['url'], $data['platform']);
        $disk = 'public';
        $filename = 'imports/'.Str::uuid()->toString().($fetch['type'] === 'video' ? '.mp4' : '.jpg');
        $storedPath = $fastServer->downloadMedia($fetch['download_url'], $filename, $disk);

        $thumbPath = null;
        if (! empty($fetch['thumbnail_url'])) {
            try {
                $thumbPath = $fastServer->downloadMedia($fetch['thumbnail_url'], 'imports/thumb-'.Str::uuid()->toString().'.jpg', $disk);
            } catch (\Throwable) {
                $thumbPath = null;
            }
        }

        $type = match ($data['platform']) {
            'tiktok' => InspirationTypeEnum::Tiktok,
            'instagram' => InspirationTypeEnum::Instagram,
            'youtube' => InspirationTypeEnum::Youtube,
            default => InspirationTypeEnum::Other,
        };

        $inspiration = Inspiration::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'source_url' => $data['url'],
            'platform' => $data['platform'],
            'media_url' => Storage::disk($disk)->url($storedPath),
            'thumbnail_url' => $thumbPath ? Storage::disk($disk)->url($thumbPath) : ($fetch['thumbnail_url'] ?? null),
            'title' => $fetch['title'],
            'duration_seconds' => $fetch['duration'],
            'media_type' => $fetch['type'] === 'video' ? MediaTypeEnum::Video : MediaTypeEnum::Image,
            'status' => InspirationStatusEnum::Processed,
        ]);

        ProcessImportedMediaJob::dispatch($inspiration->id)->afterCommit();

        $inspiration->load('groupes');

        return $this->created((new InspirationResource($inspiration))->resolve(), 'Import créé');
    }
}
