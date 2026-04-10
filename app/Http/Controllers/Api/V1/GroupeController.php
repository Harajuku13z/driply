<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\GroupeDetailResource;
use App\Http\Resources\V1\GroupeResource;
use App\Models\Groupe;
use App\Support\GroupeCoverManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class GroupeController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = Groupe::query()
            ->where('user_id', $user->id)
            ->with(['inspirations' => fn ($q) => $q->orderByPivot('position')])
            ->withCount('inspirations')
            ->orderBy('position')
            ->orderByDesc('created_at');

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, fn (Groupe $g) => (new GroupeResource($g))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $maxPos = (int) Groupe::query()->where('user_id', $user->id)->max('position');

        $groupe = Groupe::query()->create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'cover_image' => null,
            'position' => $maxPos + 1,
        ]);

        return $this->created((new GroupeResource($groupe->loadCount('inspirations')))->resolve(), 'Groupe créé');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupe = Groupe::query()
            ->where('user_id', $user->id)
            ->with(['inspirations' => fn ($q) => $q->orderByPivot('position')])
            ->withCount('inspirations')
            ->findOrFail($id);

        return $this->success((new GroupeDetailResource($groupe))->resolve());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupe = Groupe::query()->where('user_id', $user->id)->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $groupe->fill($data);
        $groupe->save();

        return $this->success((new GroupeResource($groupe->loadCount('inspirations')))->resolve(), 'Groupe mis à jour');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupe = Groupe::query()->where('user_id', $user->id)->findOrFail($id);
        $groupe->delete();

        return $this->success(null, 'Groupe supprimé');
    }

    public function updateCover(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupe = Groupe::query()->where('user_id', $user->id)->findOrFail($id);

        $data = $request->validate([
            'cover_image' => ['required', 'string', 'max:2048'],
        ]);

        $groupe->cover_image = $data['cover_image'];
        $groupe->save();

        return $this->success((new GroupeResource($groupe->loadCount('inspirations')))->resolve(), 'Couverture mise à jour');
    }

    public function reorder(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'uuid'],
            'items.*.position' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($user, $data): void {
            foreach ($data['items'] as $row) {
                Groupe::query()
                    ->where('user_id', $user->id)
                    ->where('id', $row['id'])
                    ->update(['position' => $row['position']]);
            }
        });

        return $this->success(null, 'Ordre mis à jour');
    }
}
