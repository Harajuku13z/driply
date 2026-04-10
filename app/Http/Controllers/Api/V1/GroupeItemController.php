<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\GroupeDetailResource;
use App\Http\Resources\V1\GroupeItemResource;
use App\Models\Groupe;
use App\Models\GroupeItem;
use App\Models\Inspiration;
use App\Support\GroupeCoverManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class GroupeItemController extends Controller
{
    use ApiResponses;

    public function store(Request $request, string $groupeId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupe = Groupe::query()->where('user_id', $user->id)->findOrFail($groupeId);

        $data = $request->validate([
            'inspiration_id' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:5000'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $inspiration = Inspiration::query()
            ->where('user_id', $user->id)
            ->findOrFail($data['inspiration_id']);

        if (GroupeItem::query()->where('groupe_id', $groupe->id)->where('inspiration_id', $inspiration->id)->exists()) {
            return $this->error('Cette inspiration est déjà dans le groupe.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $item = GroupeCoverManager::attachInspiration($groupe, $inspiration, [
            'note' => $data['note'] ?? null,
            'position' => $data['position'] ?? null,
        ]);

        $groupe->refresh()->load(['inspirations' => fn ($q) => $q->orderByPivot('position')])->loadCount('inspirations');

        return $this->created([
            'groupe_item' => (new GroupeItemResource($item))->resolve(),
            'groupe' => (new GroupeDetailResource($groupe))->resolve(),
        ], 'Inspiration ajoutée au groupe');
    }

    public function destroy(Request $request, string $groupeId, string $inspirationId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupe = Groupe::query()->where('user_id', $user->id)->findOrFail($groupeId);

        $deleted = GroupeItem::query()
            ->where('groupe_id', $groupe->id)
            ->where('inspiration_id', $inspirationId)
            ->delete();

        if ($deleted === 0) {
            return $this->error('Item introuvable.', Response::HTTP_NOT_FOUND);
        }

        $groupe->refresh();
        GroupeCoverManager::afterDetach($groupe);

        return $this->success(null, 'Inspiration retirée du groupe');
    }

    public function reorder(Request $request, string $groupeId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupe = Groupe::query()->where('user_id', $user->id)->findOrFail($groupeId);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.inspiration_id' => ['required', 'uuid'],
            'items.*.position' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($groupe, $data): void {
            foreach ($data['items'] as $row) {
                GroupeItem::query()
                    ->where('groupe_id', $groupe->id)
                    ->where('inspiration_id', $row['inspiration_id'])
                    ->update(['position' => $row['position']]);
            }
        });

        return $this->success(null, 'Ordre des inspirations mis à jour');
    }

    public function updateItemNote(Request $request, string $groupeId, string $inspirationId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupe = Groupe::query()->where('user_id', $user->id)->findOrFail($groupeId);

        $item = GroupeItem::query()
            ->where('groupe_id', $groupe->id)
            ->where('inspiration_id', $inspirationId)
            ->firstOrFail();

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $item->note = $data['note'] ?? null;
        $item->save();

        return $this->success((new GroupeItemResource($item))->resolve(), 'Note mise à jour');
    }
}
