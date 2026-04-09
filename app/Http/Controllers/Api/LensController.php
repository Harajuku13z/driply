<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ExternalServiceException;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lens\LensAnalyzeRequest;
use App\Http\Resources\LensResultResource;
use App\Models\LensResult;
use App\Models\Outfit;
use App\Models\User;
use App\Services\GoogleLensService;
use App\Services\LensImagePriceSearchService;
use App\Support\UnwrapGoogleUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class LensController extends Controller
{
    use ApiResponses;

    public function analyze(LensAnalyzeRequest $request, GoogleLensService $lens, LensImagePriceSearchService $lensSearch): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $currency = $user->currency_preference;

        try {
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $stored = 'lens/'.Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
                Storage::disk('public')->put($stored, (string) file_get_contents($file->getRealPath()));
                $inputPath = $stored;
            } else {
                $imageUrl = UnwrapGoogleUrl::unwrap((string) $request->validated('image_url'));
                $binary = Http::timeout(60)
                    ->withHeaders(['User-Agent' => 'Driply/1.0'])
                    ->get($imageUrl)
                    ->throw()
                    ->body();
                $stored = 'lens/'.Str::uuid()->toString().'.jpg';
                Storage::disk('public')->put($stored, $binary);
                $inputPath = $stored;
            }

            $inputPreviewUrl = $lens->absolutePublicUrlForStoredPath($inputPath);
            if (str_contains($inputPreviewUrl, 'localhost') || str_contains($inputPreviewUrl, '127.0.0.1')) {
                Log::warning('driply.lens_image_public_url_not_reachable', [
                    'hint' => 'Définir APP_URL ou DRIPLY_LENS_PUBLIC_STORAGE_BASE_URL en HTTPS pour SerpAPI Google Lens.',
                    'url' => $inputPreviewUrl,
                ]);
            }

            $payload = $lensSearch->searchAndAnalyze($inputPreviewUrl, $currency);
            $allProducts = $payload['all_products'];
            $priceAnalysis = $payload['price_analysis'];
            $top3 = $payload['top_3'];
            $topResults = $payload['top_results'];
            $queryUsed = $payload['query_used'];
            $itemDetected = $payload['item_detected'];
            $brand = $payload['brand'];
            $color = $payload['color'];
            $priceSummary = $payload['price_summary'];

            $record = LensResult::query()->create([
                'user_id' => $user->id,
                'input_image_url' => $inputPath,
                'lens_products' => $allProducts,
                'price_analysis' => $priceAnalysis,
                'currency' => $currency,
            ]);

            return $this->created([
                'lens_result_id' => $record->id,
                'input_image_public_url' => $inputPreviewUrl,
                'query_used' => $queryUsed,
                'item_detected' => $itemDetected,
                'brand' => $brand,
                'color' => $color,
                'price_summary' => $priceSummary,
                'top_results' => $topResults,
                'all_products' => $allProducts,
                'price_analysis' => $priceAnalysis,
                'top_3' => $top3,
            ], 'Analyse terminée');
        } catch (ExternalServiceException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_GATEWAY);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $paginator = LensResult::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($perPage);

        return $this->paginated($paginator, fn (LensResult $r) => (new LensResultResource($r))->resolve());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $result = LensResult::query()->findOrFail($id);
        if (! $request->user()?->can('view', $result)) {
            abort(403);
        }

        return $this->success((new LensResultResource($result))->resolve());
    }

    public function attachOutfit(Request $request, string $id): JsonResponse
    {
        $result = LensResult::query()->findOrFail($id);
        if (! $request->user()?->can('update', $result)) {
            abort(403);
        }

        $data = $request->validate([
            'outfit_id' => ['required', 'uuid', Rule::exists('outfits', 'id')->where('user_id', (int) $request->user()?->id)],
        ]);

        $outfit = Outfit::query()->findOrFail($data['outfit_id']);
        if (! $request->user()?->can('update', $outfit)) {
            abort(403);
        }

        $result->outfit_id = $outfit->id;
        $result->save();

        $analysis = is_array($result->price_analysis) ? $result->price_analysis : [];
        $mid = $analysis['price_mid'] ?? $analysis['estimated_price_mid'] ?? null;

        if ($outfit->price === null && is_numeric($mid)) {
            $outfit->price = $mid;
            $outfit->save();
        }

        return $this->success((new LensResultResource($result))->resolve(), 'Lens result attached');
    }
}
