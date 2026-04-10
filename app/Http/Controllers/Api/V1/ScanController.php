<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\InspirationStatusEnum;
use App\Enums\InspirationTypeEnum;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InspirationResource;
use App\Models\Inspiration;
use App\Services\DriplyV1ScanAnalysisService;
use App\Services\GoogleLensService;
use App\Services\GoogleShoppingService;
use App\Support\LensPublicImageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScanController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly GoogleLensService $lens,
        private readonly GoogleShoppingService $shopping,
        private readonly DriplyV1ScanAnalysisService $priceV1,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $request->validate([
            'image' => ['required', 'file', 'image', 'max:15360'],
        ]);

        $file = $request->file('image');
        $path = 'scans/'.Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        $publicUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($path);

        $lensRaw = $this->lens->analyzeImage($publicUrl);
        $products = $this->lens->extractProducts($lensRaw);

        $shoppingQuery = 'vêtement mode';
        if ($products !== []) {
            $shoppingQuery = (string) ($products[0]['title'] ?? $shoppingQuery);
        }

        if (count($products) < 5) {
            $extra = $this->shopping->search($shoppingQuery, 12);
            $products = array_merge($products, $extra);
        }

        $currency = $user->currency ?? 'EUR';
        $analysis = $this->priceV1->analyze($products, $currency);

        $firstThumb = '';
        foreach ($analysis['scan_results'] as $row) {
            if (is_array($row) && ! empty($row['thumbnail'])) {
                $firstThumb = (string) $row['thumbnail'];
                break;
            }
        }

        $inspiration = Inspiration::query()->create([
            'user_id' => $user->id,
            'type' => InspirationTypeEnum::Scan,
            'scan_query' => $shoppingQuery,
            'scan_item_type' => $analysis['item_type'],
            'scan_brand' => $analysis['brand'],
            'scan_color' => $analysis['color'],
            'scan_results' => $analysis['scan_results'],
            'scan_price_summary' => $analysis['scan_price_summary'],
            'thumbnail_url' => $firstThumb !== '' ? $firstThumb : $publicUrl,
            'title' => $analysis['item_type'],
            'status' => InspirationStatusEnum::Processed,
        ]);

        $inspiration->load('groupes');

        return $this->created([
            'inspiration' => (new InspirationResource($inspiration))->resolve(),
            'lens_raw' => $lensRaw,
            'analysis' => $analysis,
        ], 'Scan enregistré');
    }
}
