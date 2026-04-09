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
use App\Services\PriceAnalysisService;
use App\Support\UnwrapGoogleUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class LensController extends Controller
{
    use ApiResponses;

    public function analyze(LensAnalyzeRequest $request, GoogleLensService $lens, PriceAnalysisService $prices): JsonResponse
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

            $raw = $lens->analyzeImage($inputPath);
            $products = $lens->extractTopVisualMatches($raw, 3);

            $filteredLensResults = array_values(array_map(function (array $p) {
                return [
                    'title' => (string) ($p['title'] ?? ''),
                    'source' => (string) ($p['source'] ?? ''),
                    'thumbnail_url' => (string) ($p['thumbnail_url'] ?? ''),
                    'image_url' => (string) ($p['image_url'] ?? ''),
                    'product_url' => (string) ($p['product_url'] ?? ''),
                    'price_found' => $p['price_found'] ?? null,
                    'currency_found' => $p['currency_found'] ?? null,
                ];
            }, $products));

            $analysis = $prices->analyzeFromLensResults($products, $currency);

            $record = LensResult::query()->create([
                'user_id' => $user->id,
                'input_image_url' => $inputPath,
                'lens_products' => $filteredLensResults,
                'price_analysis' => $analysis,
                'currency' => $currency,
            ]);

            return $this->created([
                'lens_result_id' => $record->id,
                'lens_results' => $filteredLensResults,
                'price_analysis' => $analysis,
            ], 'Lens analysis complete');
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
        $mid = $analysis['estimated_price_mid'] ?? null;

        if ($outfit->price === null && is_numeric($mid)) {
            $outfit->price = $mid;
            $outfit->save();
        }

        return $this->success((new LensResultResource($result))->resolve(), 'Lens result attached');
    }
}
