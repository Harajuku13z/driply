<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\InspirationTypeEnum;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\GroupeResource;
use App\Http\Resources\V1\InspirationResource;
use App\Models\Groupe;
use App\Models\Inspiration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponses;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $groupesCount = Groupe::query()->where('user_id', $user->id)->count();
        $inspirationsCount = Inspiration::query()->where('user_id', $user->id)->count();
        $scansCount = Inspiration::query()->where('user_id', $user->id)->where('type', InspirationTypeEnum::Scan)->count();
        $importsCount = Inspiration::query()->where('user_id', $user->id)->whereIn('type', [
            InspirationTypeEnum::Tiktok,
            InspirationTypeEnum::Instagram,
            InspirationTypeEnum::Youtube,
            InspirationTypeEnum::Other,
        ])->count();
        $favoritesCount = Inspiration::query()->where('user_id', $user->id)->where('is_favorite', true)->count();

        $recentInspirations = Inspiration::query()
            ->where('user_id', $user->id)
            ->with('groupes')
            ->orderByDesc('created_at')
            ->limit(4)
            ->get();

        $recentGroupes = Groupe::query()
            ->where('user_id', $user->id)
            ->with(['inspirations' => fn ($q) => $q->orderByPivot('position')])
            ->withCount('inspirations')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        return $this->success([
            'groupes_count' => $groupesCount,
            'inspirations_count' => $inspirationsCount,
            'scans_count' => $scansCount,
            'imports_count' => $importsCount,
            'favorites_count' => $favoritesCount,
            'recent_inspirations' => InspirationResource::collection($recentInspirations)->resolve(),
            'recent_groupes' => GroupeResource::collection($recentGroupes)->resolve(),
        ]);
    }
}
