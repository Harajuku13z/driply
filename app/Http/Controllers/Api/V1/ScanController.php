<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\InspirationStatusEnum;
use App\Enums\InspirationTypeEnum;
use App\Exceptions\InspirationAnalysisException;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ScanResultResource;
use App\Models\Inspiration;
use App\Services\Vision\OutfitSearchPipeline;
use App\Support\LensPublicImageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScanController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly OutfitSearchPipeline $pipeline,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $request->validate([
            'image' => ['required', 'file', 'image', 'max:15360'],
        ]);

        // ── Upload de l'image ──
        $file = $request->file('image');
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $path = 'scans/' . Str::uuid()->toString() . '.' . $extension;

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        $publicUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($path);

        // ── Conversion base64 pour GPT-4o Vision ──
        $imageContent = (string) file_get_contents($file->getRealPath());
        $base64Image  = base64_encode($imageContent);
        $mimeType     = $file->getMimeType() ?: 'image/jpeg';

        try {
            // ── Pipeline complet (9 etapes) ──
            $result = $this->pipeline->execute($publicUrl, $base64Image, $mimeType);

            // ── Thumbnail : premiere image trouvee ou l'upload ──
            $thumbnail = $publicUrl;
            foreach ($result['scan_results'] as $product) {
                if (! empty($product['image_url'])) {
                    $thumbnail = (string) $product['image_url'];
                    break;
                }
            }

            // ── Sauvegarde en base ──
            $inspiration = Inspiration::query()->create([
                'user_id'            => $user->id,
                'type'               => InspirationTypeEnum::Scan,
                'scan_query'         => $result['query'],
                'scan_item_type'     => $result['item_type'],
                'scan_brand'         => $result['brand'],
                'scan_color'         => $result['color'],
                'scan_results'       => $result['scan_results'],
                'scan_price_summary' => $result['scan_price_summary'],
                'thumbnail_url'      => $thumbnail,
                'title'              => $result['item_type'],
                'status'             => InspirationStatusEnum::Processed,
            ]);

            $inspiration->load('groupes');

            return $this->created([
                'inspiration' => (new ScanResultResource($inspiration))->resolve(),
            ], 'Scan analyse avec succes.');

        } catch (InspirationAnalysisException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('ScanController: erreur pipeline', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Une erreur est survenue lors de l\'analyse. Reessaie.', 500);
        }
    }
}
