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
use App\Models\User;
use App\Services\Vision\OutfitSearchPipeline;
use App\Support\LensPublicImageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ScanController extends Controller
{
    use ApiResponses;

    private const PREVIEW_CACHE_PREFIX = 'inspiration_scan_preview:';

    public function __construct(
        private readonly OutfitSearchPipeline $pipeline,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'image' => ['required', 'file', 'image', 'max:15360'],
            'persist' => ['sometimes', 'nullable'],
        ]);

        $persist = $this->parsePersistFlag($request->input('persist'), default: true);

        $file = $request->file('image');
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $path = 'scans/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        $publicUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($path);

        $imageContent = (string) file_get_contents($file->getRealPath());
        $base64Image = base64_encode($imageContent);
        $mimeType = $file->getMimeType() ?: 'image/jpeg';

        try {
            $result = $this->pipeline->execute($publicUrl, $base64Image, $mimeType);
            $this->ensureMinimumScanResultCount($result);

            // Vignette = photo scannée (URL publique upload). Ne pas la remplacer par une image Shopping :
            // l’utilisateur doit reconnaître sa capture ; les visuels produits restent dans `scan_results`.
            $thumbnail = $publicUrl;

            if (! $persist) {
                $token = (string) Str::uuid();
                Cache::put(
                    self::previewCacheKey($user->id, $token),
                    [
                        'user_id' => $user->id,
                        'disk_path' => $path,
                        'thumbnail' => $thumbnail,
                        'pipeline' => $result,
                    ],
                    now()->addMinutes(45)
                );

                $virtual = $this->virtualInspirationFromPipeline($user, $result, $thumbnail);

                return $this->success([
                    'preview_token' => $token,
                    'inspiration' => (new ScanResultResource($virtual))->resolve(),
                ], 'Analyse prete. Enregistre sur ton compte pour voir l\'inspiration dans Ajouts recents.');
            }

            $inspiration = $this->persistInspiration($user, $result, $thumbnail);
            $inspiration->load('groupes');

            return $this->created([
                'preview_token' => null,
                'inspiration' => (new ScanResultResource($inspiration))->resolve(),
            ], 'Scan analyse avec succes.');

        } catch (InspirationAnalysisException $e) {
            Storage::disk('public')->delete($path);

            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            Log::error('ScanController: erreur pipeline', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Une erreur est survenue lors de l\'analyse. Reessaie.', 500);
        }
    }

    /**
     * Transforme une previsualisation cachee en inspiration en base (Ajouts recents, synchro app).
     */
    public function confirm(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'preview_token' => ['required', 'string', 'uuid'],
        ]);

        $token = (string) $request->input('preview_token');
        $key = self::previewCacheKey($user->id, $token);
        $payload = Cache::pull($key);

        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $user->id) {
            return $this->error('Previsualisation expiree ou introuvable. Refais un scan.', 422);
        }

        $result = $payload['pipeline'] ?? null;
        $thumbnail = (string) ($payload['thumbnail'] ?? '');

        if (! is_array($result) || $thumbnail === '') {
            return $this->error('Donnees de previsualisation invalides.', 422);
        }

        try {
            $this->ensureMinimumScanResultCount($result);
        } catch (InspirationAnalysisException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $inspiration = $this->persistInspiration($user, $result, $thumbnail);
        $inspiration->load('groupes');

        return $this->created([
            'preview_token' => null,
            'inspiration' => (new ScanResultResource($inspiration))->resolve(),
        ], 'Inspiration enregistree sur ton compte.');
    }

    private static function previewCacheKey(string $userId, string $token): string
    {
        return self::PREVIEW_CACHE_PREFIX.$userId.':'.$token;
    }

    private function parsePersistFlag(mixed $raw, bool $default): bool
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string) $raw));

        return match ($s) {
            '0', 'false', 'no', 'off' => false,
            '1', 'true', 'yes', 'on' => true,
            default => $default,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     *
     * @throws InspirationAnalysisException
     */
    private function ensureMinimumScanResultCount(array $result): void
    {
        $min = max(1, (int) config('vision.limits.min_scan_results', 10));
        $list = $result['scan_results'] ?? [];
        $n = is_array($list) ? count($list) : 0;

        if ($n < $min) {
            throw new InspirationAnalysisException(
                "Seulement {$n} offre(s) trouvee(s) (minimum {$min}). Reessaie avec une autre photo ou verifie SERPAPI_KEY."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function virtualInspirationFromPipeline(User $user, array $result, string $thumbnail): Inspiration
    {
        $virtual = new Inspiration([
            'user_id' => $user->id,
            'type' => InspirationTypeEnum::Scan,
            'scan_query' => $result['query'],
            'scan_item_type' => $result['item_type'],
            'scan_brand' => $result['brand'],
            'scan_color' => $result['color'],
            'scan_results' => $result['scan_results'],
            'scan_price_summary' => $result['scan_price_summary'],
            'thumbnail_url' => $thumbnail,
            'title' => $result['item_type'],
            'status' => InspirationStatusEnum::Processed,
        ]);
        $virtual->id = (string) Str::uuid();
        $virtual->exists = false;

        return $virtual;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistInspiration(User $user, array $result, string $thumbnail): Inspiration
    {
        return Inspiration::query()->create([
            'user_id' => $user->id,
            'type' => InspirationTypeEnum::Scan,
            'scan_query' => $result['query'],
            'scan_item_type' => $result['item_type'],
            'scan_brand' => $result['brand'],
            'scan_color' => $result['color'],
            'scan_results' => $result['scan_results'],
            'scan_price_summary' => $result['scan_price_summary'],
            'thumbnail_url' => $thumbnail,
            'title' => $result['item_type'],
            'status' => InspirationStatusEnum::Processed,
        ]);
    }
}
