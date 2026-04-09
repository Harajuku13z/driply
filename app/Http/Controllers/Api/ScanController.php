<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ScanSessionStatus;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\ScanDuplicatesJob;
use App\Models\DuplicateGroup;
use App\Models\OutfitImage;
use App\Models\ScanSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ScanController extends Controller
{
    use ApiResponses;

    public function start(Request $request): JsonResponse
    {
        $session = ScanSession::query()->create([
            'user_id' => $request->user()->id,
            'status' => ScanSessionStatus::Pending,
            'total_images' => 0,
            'duplicates_found' => 0,
            'processed_images' => 0,
        ]);

        ScanDuplicatesJob::dispatch($session->id);

        return $this->created([
            'scan_id' => $session->id,
            'status' => $session->status->value,
        ], 'Scan queued');
    }

    public function show(Request $request, string $scanId): JsonResponse
    {
        $session = ScanSession::query()->findOrFail($scanId);
        if (! $request->user()?->can('view', $session)) {
            abort(403);
        }

        return $this->success([
            'scan_id' => $session->id,
            'status' => $session->status->value,
            'total_images' => $session->total_images,
            'processed_images' => $session->processed_images,
            'duplicates_found' => $session->duplicates_found,
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
        ]);
    }

    public function results(Request $request, string $scanId): JsonResponse
    {
        $session = ScanSession::query()->findOrFail($scanId);
        if (! $request->user()?->can('view', $session)) {
            abort(403);
        }

        $groups = $session->duplicateGroups()->where('resolved', false)->get()->map(function (DuplicateGroup $g) {
            return [
                'group_id' => $g->id,
                'image_ids' => $g->image_ids,
                'similarity_score' => $g->similarity_score,
            ];
        });

        return $this->success(['groups' => $groups]);
    }

    public function resolve(Request $request, string $scanId): JsonResponse
    {
        $session = ScanSession::query()->findOrFail($scanId);
        if (! $request->user()?->can('view', $session)) {
            abort(403);
        }

        $data = $request->validate([
            'group_id' => ['required', 'uuid'],
            'action' => ['required', 'in:keep_first,keep_all,delete_duplicates'],
            'keep_id' => ['nullable', 'uuid'],
        ]);

        $group = DuplicateGroup::query()
            ->where('scan_session_id', $session->id)
            ->findOrFail($data['group_id']);

        if (! $request->user()?->can('resolve', $group)) {
            abort(403);
        }

        /** @var array<int, string> $ids */
        $ids = $group->image_ids;

        DB::transaction(function () use ($group, $data, $ids, $session): void {
            $action = $data['action'];

            if ($action === 'keep_all') {
                $group->resolved = true;
                $group->resolution_action = 'keep_all';
                $group->save();

                return;
            }

            if ($action === 'keep_first') {
                $keep = $ids[0] ?? null;
                $this->deleteImageIdsExcept($session, $ids, $keep);
                $group->resolved = true;
                $group->resolution_action = 'keep_first';
                $group->save();

                return;
            }

            if ($action === 'delete_duplicates') {
                $keep = $data['keep_id'] ?? ($ids[0] ?? null);
                $this->deleteImageIdsExcept($session, $ids, $keep);
                $group->resolved = true;
                $group->resolution_action = 'delete_duplicates';
                $group->save();
            }
        });

        return $this->success(null, 'Duplicate group resolved');
    }

    public function history(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = ScanSession::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($perPage);

        return $this->paginated($paginator, function (ScanSession $s) {
            return [
                'scan_id' => $s->id,
                'status' => $s->status->value,
                'total_images' => $s->total_images,
                'duplicates_found' => $s->duplicates_found,
                'started_at' => $s->started_at?->toIso8601String(),
                'completed_at' => $s->completed_at?->toIso8601String(),
            ];
        });
    }

    /**
     * @param  array<int, string>  $ids
     */
    private function deleteImageIdsExcept(ScanSession $session, array $ids, ?string $keepId): void
    {
        foreach ($ids as $imageId) {
            if ($keepId !== null && $imageId === $keepId) {
                continue;
            }

            $image = OutfitImage::query()->find($imageId);
            if ($image === null) {
                continue;
            }

            $outfit = $image->outfit;
            if ($outfit === null || (int) $outfit->user_id !== (int) $session->user_id) {
                continue;
            }

            if (! str_starts_with($image->url, 'http://') && ! str_starts_with($image->url, 'https://')) {
                Storage::disk('public')->delete($image->url);
            }

            $image->delete();
        }
    }
}
