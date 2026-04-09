<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Http\Resources\OutfitResource;
use App\Models\ImportedMedia;
use App\Models\LensResult;
use App\Models\Outfit;
use App\Models\ScanSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponses;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $currency = $user->currency_preference;

        $totalOutfits = $user->outfits()->count();
        $totalValue = (float) ($user->outfits()->sum('price') ?? 0);
        $totalMedia = ImportedMedia::query()->where('user_id', $user->id)->count();
        $totalLens = LensResult::query()->where('user_id', $user->id)->count();

        $lastScan = ScanSession::query()
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        $recentOutfits = $user->outfits()
            ->with(['images', 'tagModels'])
            ->latest()
            ->limit(5)
            ->get();

        $priced = $user->outfits()->whereNotNull('price')->orderByDesc('price');

        $mostExpensive = (clone $priced)->first();
        $cheapest = $user->outfits()->whereNotNull('price')->orderBy('price')->first();
        $avg = (float) ($user->outfits()->whereNotNull('price')->avg('price') ?? 0);

        $payload = [
            'total_outfits' => $totalOutfits,
            'total_value' => round($totalValue, 2),
            'currency' => $currency,
            'total_media_imported' => $totalMedia,
            'total_lens_searches' => $totalLens,
            'last_scan' => $lastScan !== null ? [
                'date' => $lastScan->completed_at?->toIso8601String() ?? $lastScan->created_at->toIso8601String(),
                'duplicates_found' => $lastScan->duplicates_found,
            ] : null,
            'recent_outfits' => $recentOutfits->map(fn (Outfit $o) => (new OutfitResource($o))->resolve())->values()->all(),
            'price_insights' => [
                'most_expensive_outfit' => $mostExpensive !== null ? [
                    'title' => $mostExpensive->title,
                    'price' => (float) $mostExpensive->price,
                ] : null,
                'cheapest_outfit' => $cheapest !== null ? [
                    'title' => $cheapest->title,
                    'price' => (float) $cheapest->price,
                ] : null,
                'average_outfit_price' => round($avg, 2),
            ],
        ];

        return $this->success((new DashboardResource($payload))->resolve());
    }
}
