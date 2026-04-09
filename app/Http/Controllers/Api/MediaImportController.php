<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Exceptions\ExternalServiceException;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ImportMediaRequest;
use App\Http\Resources\ImportedMediaResource;
use App\Jobs\ExtractVideoFramesJob;
use App\Jobs\ProcessImportedMediaJob;
use App\Models\ImportedMedia;
use App\Models\Outfit;
use App\Services\FastServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class MediaImportController extends Controller
{
    use ApiResponses;

    public function store(ImportMediaRequest $request, FastServerService $fastServer): JsonResponse
    {
        try {
            $meta = $fastServer->fetchMedia($request->validated('url'), $request->validated('platform')->value);
        } catch (ExternalServiceException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_GATEWAY);
        }

        $type = MediaType::from($meta['type']);

        $media = ImportedMedia::query()->create([
            'user_id' => $request->user()->id,
            'platform' => $request->validated('platform'),
            'source_url' => $request->validated('url'),
            'thumbnail_url' => $meta['thumbnail_url'],
            'title' => $meta['title'],
            'duration_seconds' => $meta['duration'],
            'type' => $type,
            'status' => MediaStatus::Pending,
        ]);

        ProcessImportedMediaJob::dispatch($media->id);

        return $this->created([
            'media_id' => $media->id,
            'status' => $media->status->value,
        ], 'Import queued');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = ImportedMedia::query()->where('user_id', $request->user()->id);

        if ($request->filled('platform')) {
            $query->where('platform', $request->string('platform'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $paginator = $query->latest()->paginate($perPage);

        return $this->paginated($paginator, fn (ImportedMedia $m) => (new ImportedMediaResource($m))->resolve());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $media = ImportedMedia::query()->findOrFail($id);
        if (! $request->user()?->can('view', $media)) {
            abort(403);
        }

        return $this->success((new ImportedMediaResource($media))->resolve());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $media = ImportedMedia::query()->findOrFail($id);
        if (! $request->user()?->can('delete', $media)) {
            abort(403);
        }

        if ($media->local_path !== null) {
            Storage::disk('media')->delete($media->local_path);
        }

        if (is_array($media->frames)) {
            foreach ($media->frames as $framePath) {
                Storage::disk('media')->delete((string) $framePath);
            }
        }

        $media->delete();

        return $this->success(null, 'Media deleted');
    }

    public function extractFrames(Request $request, string $id): JsonResponse
    {
        $media = ImportedMedia::query()->findOrFail($id);
        if (! $request->user()?->can('update', $media)) {
            abort(403);
        }

        ExtractVideoFramesJob::dispatch($media->id);

        return $this->success(['status' => 'queued'], 'Frame extraction queued');
    }

    public function attachOutfit(Request $request, string $id): JsonResponse
    {
        $media = ImportedMedia::query()->findOrFail($id);
        if (! $request->user()?->can('update', $media)) {
            abort(403);
        }

        $data = $request->validate([
            'outfit_id' => ['required', 'uuid', Rule::exists('outfits', 'id')->where('user_id', (int) $request->user()?->id)],
        ]);

        $outfit = Outfit::query()->findOrFail($data['outfit_id']);
        if (! $request->user()?->can('update', $outfit)) {
            abort(403);
        }

        $media->outfit_id = $outfit->id;
        $media->save();

        return $this->success((new ImportedMediaResource($media))->resolve(), 'Media attached to outfit');
    }
}
