<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\InspirationStatusEnum;
use App\Enums\InspirationTypeEnum;
use App\Enums\MediaTypeEnum;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InspirationResource;
use App\Jobs\ProcessImportedMediaJob;
use App\Models\GroupeItem;
use App\Models\Inspiration;
use App\Models\User;
use App\Services\FastServerService;
use App\Services\LinkPreviewService;
use App\Support\LensPublicImageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InspirationController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Inspiration::query()->where('user_id', $user->id)->with('groupes');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->boolean('favorite')) {
            $query->where('is_favorite', true);
        }

        if ($request->filled('groupe_id')) {
            $gid = $request->string('groupe_id')->toString();
            $query->whereHas('groupes', fn ($q) => $q->where('groupes.id', $gid));
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) $request->query('per_page', 20));

        return $this->paginated($paginator, fn (Inspiration $i) => (new InspirationResource($i))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'type' => ['required', 'string', 'in:scan,photo,tiktok,instagram,youtube,other'],
            'title' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'platform' => ['nullable', 'string', 'max:32'],
            'media_type' => ['nullable', 'string', 'in:image,video'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $inspiration = Inspiration::query()->create([
            'user_id' => $user->id,
            'type' => InspirationTypeEnum::from($data['type']),
            'title' => $data['title'] ?? null,
            'note' => $data['note'] ?? null,
            'tags' => $data['tags'] ?? null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'source_url' => $data['source_url'] ?? null,
            'platform' => $data['platform'] ?? null,
            'media_type' => isset($data['media_type']) ? MediaTypeEnum::from($data['media_type']) : null,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'status' => InspirationStatusEnum::Processed,
        ]);

        $inspiration->load('groupes');

        return $this->created((new InspirationResource($inspiration))->resolve(), 'Inspiration créée');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $inspiration = Inspiration::query()
            ->where('user_id', $user->id)
            ->with('groupes')
            ->findOrFail($id);

        return $this->success((new InspirationResource($inspiration))->resolve());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $inspiration = Inspiration::query()->where('user_id', $user->id)->findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'media_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $inspiration->fill($data);
        $inspiration->save();
        $inspiration->load('groupes');

        return $this->success((new InspirationResource($inspiration))->resolve(), 'Inspiration mise à jour');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $inspiration = Inspiration::query()->where('user_id', $user->id)->findOrFail($id);
        GroupeItem::query()->where('inspiration_id', $inspiration->id)->delete();
        $inspiration->delete();

        return $this->success(null, 'Inspiration supprimée');
    }

    public function toggleFavorite(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $inspiration = Inspiration::query()->where('user_id', $user->id)->findOrFail($id);
        $inspiration->is_favorite = ! $inspiration->is_favorite;
        $inspiration->save();
        $inspiration->load('groupes');

        return $this->success((new InspirationResource($inspiration))->resolve());
    }

    public function import(Request $request, FastServerService $fastServer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'platform' => ['required', 'string', 'in:tiktok,instagram,youtube,other'],
        ]);

        $type = match ($data['platform']) {
            'tiktok' => InspirationTypeEnum::Tiktok,
            'instagram' => InspirationTypeEnum::Instagram,
            'youtube' => InspirationTypeEnum::Youtube,
            default => InspirationTypeEnum::Other,
        };

        $isSocialPlatform = in_array($data['platform'], ['tiktok', 'instagram', 'youtube'], true);

        // ── Réseaux sociaux : télécharger via FastServer ──
        if ($isSocialPlatform) {
            try {
                $fetch = $fastServer->fetchMedia($data['url'], $data['platform']);
            } catch (\Throwable) {
                $fetch = null;
            }
        } else {
            $fetch = null;
        }

        $mediaUrl = null;
        $thumbUrl = null;
        $faviconUrl = null;
        $additionalImages = [];
        $title = null;
        $duration = null;
        $mediaType = MediaTypeEnum::Image;
        $note = null;
        $disk = 'public';

        if ($fetch !== null) {
            // ── FastServer a renvoyé un résultat : télécharger le média principal ──
            $filename = 'imports/'.Str::uuid()->toString().($fetch['type'] === 'video' ? '.mp4' : '.jpg');
            $storedPath = $fastServer->downloadMedia($fetch['download_url'], $filename, $disk);
            $mediaUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($storedPath);
            $mediaType = $fetch['type'] === 'video' ? MediaTypeEnum::Video : MediaTypeEnum::Image;
            $duration = $fetch['duration'];

            // Caption → note (texte complet), title tronqué pour l'affichage
            $rawCaption = $fetch['title'];
            if ($rawCaption !== null && $rawCaption !== '') {
                $note = $data['url']."\n\n".$rawCaption;
                // Titre = première ligne ou premiers 120 caractères
                $firstLine = Str::before($rawCaption, "\n");
                $title = Str::limit(trim($firstLine), 120);
            } else {
                $title = $this->hostFromUrl($data['url']);
                $note = $data['url'];
            }

            if (! empty($fetch['thumbnail_url'])) {
                try {
                    $thumbPath = $fastServer->downloadMedia($fetch['thumbnail_url'], 'imports/thumb-'.Str::uuid()->toString().'.jpg', $disk);
                    $thumbUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($thumbPath);
                } catch (\Throwable) {
                    $thumbUrl = $fetch['thumbnail_url'];
                }
            }

            // Garantir une thumbnail : si pas de thumb mais media est une image, réutiliser
            if (empty($thumbUrl) && $mediaType === MediaTypeEnum::Image && ! empty($mediaUrl)) {
                $thumbUrl = $mediaUrl;
            }

            // ── Carrousel : télécharger les images supplémentaires (max 20) ──
            foreach (array_slice($fetch['additional_images'] ?? [], 0, 20) as $extraUrl) {
                try {
                    $ext = Str::lower(pathinfo(parse_url($extraUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                    $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $ext : 'jpg';
                    $extraPath = 'imports/extra-'.Str::uuid()->toString().'.'.$ext;
                    $storedExtra = $fastServer->downloadMedia($extraUrl, $extraPath, $disk);
                    $additionalImages[] = LensPublicImageUrl::absoluteFromPublicDiskPath($storedExtra);
                } catch (\Throwable) {
                    $additionalImages[] = $extraUrl;
                }
            }
        } else {
            // ── Lien web normal : scraping robuste via LinkPreviewService ──
            $linkPreview = app(LinkPreviewService::class);
            $preview = $linkPreview->preview($data['url']);

            $title = $preview['title'];

            // Télécharger l'image en local pour affichage fiable
            $imageUrl = $preview['image'];
            if (! empty($imageUrl)) {
                try {
                    $ext = Str::lower(pathinfo(parse_url($imageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                    $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true) ? $ext : 'jpg';
                    $imgPath = 'imports/og-'.Str::uuid()->toString().'.'.$ext;
                    $imgBin = Http::timeout(20)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                            'Accept' => 'image/*,*/*;q=0.8',
                            'Referer' => $data['url'],
                        ])
                        ->get($imageUrl)->throw()->body();
                    if (strlen($imgBin) > 500) {
                        Storage::disk($disk)->put($imgPath, $imgBin);
                        $thumbUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($imgPath);
                        $mediaUrl = $thumbUrl;
                    } else {
                        $thumbUrl = $imageUrl;
                    }
                } catch (\Throwable) {
                    $thumbUrl = $imageUrl;
                }
            }

            // Favicon de secours quand aucune image n'a été trouvée
            if (empty($thumbUrl)) {
                $faviconUrl = $preview['favicon'];
            }

            // Site name pour enrichir l'affichage
            $siteName = $preview['site_name'];

            // Note : prix (données structurées uniquement) + lien source
            $noteParts = [];
            if (! empty($preview['price_amount']) && $preview['price_amount'] > 0) {
                $currency = $preview['price_currency'] ?? 'EUR';
                $noteParts[] = 'Prix : '.number_format($preview['price_amount'], 2, ',', '').' '.($currency === 'EUR' ? '€' : $currency);
            }
            $noteParts[] = $data['url'];
            if (! empty($siteName)) {
                $noteParts[] = 'Source : '.$siteName;
            }
            $note = implode("\n", $noteParts);
        }

        $inspiration = Inspiration::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'source_url' => $data['url'],
            'platform' => $data['platform'],
            'media_url' => $mediaUrl,
            'thumbnail_url' => $thumbUrl,
            'additional_images' => ! empty($additionalImages) ? $additionalImages : null,
            'favicon_url' => $faviconUrl,
            'title' => $title ?: $this->hostFromUrl($data['url']),
            'duration_seconds' => $duration,
            'media_type' => $mediaType,
            'note' => $note,
            'status' => InspirationStatusEnum::Processed,
        ]);

        if ($fetch !== null) {
            ProcessImportedMediaJob::dispatch($inspiration->id)->afterCommit();
        }

        $inspiration->load('groupes');

        return $this->created((new InspirationResource($inspiration))->resolve(), 'Import créé');
    }

    // fetchOpenGraphMeta / extractPriceFromHtml : remplacés par LinkPreviewService

    private function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'Lien partagé';
    }

    /**
     * Upload direct d'une image (partage Instagram, photo galerie) — pas d'analyse Lens, création immédiate.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'image' => ['required', 'file', 'image', 'max:15360'],
            'title' => ['nullable', 'string', 'max:500'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'platform' => ['nullable', 'string', 'in:instagram,tiktok,youtube,other'],
        ]);

        $file = $request->file('image');
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $path = 'imports/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        $publicUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($path);

        $platform = $request->input('platform', 'other');
        $type = match ($platform) {
            'instagram' => InspirationTypeEnum::Instagram,
            'tiktok' => InspirationTypeEnum::Tiktok,
            'youtube' => InspirationTypeEnum::Youtube,
            default => InspirationTypeEnum::Photo,
        };

        $inspiration = Inspiration::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $request->input('title', 'Photo partagée'),
            'thumbnail_url' => $publicUrl,
            'media_url' => $publicUrl,
            'source_url' => $request->input('source_url'),
            'platform' => $platform,
            'media_type' => MediaTypeEnum::Image,
            'status' => InspirationStatusEnum::Processed,
        ]);

        $inspiration->load('groupes');

        return $this->created((new InspirationResource($inspiration))->resolve(), 'Photo enregistrée dans Mes look');
    }
}
