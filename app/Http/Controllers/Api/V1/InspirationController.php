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
use App\Support\LensPublicImageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $title = null;
        $duration = null;
        $mediaType = MediaTypeEnum::Image;

        if ($fetch !== null) {
            // FastServer a renvoyé un résultat : télécharger le média
            $disk = 'public';
            $filename = 'imports/'.Str::uuid()->toString().($fetch['type'] === 'video' ? '.mp4' : '.jpg');
            $storedPath = $fastServer->downloadMedia($fetch['download_url'], $filename, $disk);
            $mediaUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($storedPath);
            $mediaType = $fetch['type'] === 'video' ? MediaTypeEnum::Video : MediaTypeEnum::Image;
            $title = $fetch['title'];
            $duration = $fetch['duration'];

            if (! empty($fetch['thumbnail_url'])) {
                try {
                    $thumbPath = $fastServer->downloadMedia($fetch['thumbnail_url'], 'imports/thumb-'.Str::uuid()->toString().'.jpg', $disk);
                    $thumbUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($thumbPath);
                } catch (\Throwable) {
                    $thumbUrl = $fetch['thumbnail_url'];
                }
            }
        } else {
            // ── Lien web normal : récupérer les métadonnées OG (titre + image) ──
            $og = $this->fetchOpenGraphMeta($data['url']);
            $title = $og['title'];
            $thumbUrl = $og['image'];
        }

        $inspiration = Inspiration::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'source_url' => $data['url'],
            'platform' => $data['platform'],
            'media_url' => $mediaUrl,
            'thumbnail_url' => $thumbUrl,
            'title' => $title ?: $this->hostFromUrl($data['url']),
            'duration_seconds' => $duration,
            'media_type' => $mediaType,
            'status' => InspirationStatusEnum::Processed,
        ]);

        if ($fetch !== null) {
            ProcessImportedMediaJob::dispatch($inspiration->id)->afterCommit();
        }

        $inspiration->load('groupes');

        return $this->created((new InspirationResource($inspiration))->resolve(), 'Import créé');
    }

    /**
     * Récupère titre + image OG d'une page web (fallback quand FastServer ne supporte pas l'URL).
     *
     * @return array{title: string|null, image: string|null}
     */
    private function fetchOpenGraphMeta(string $url): array
    {
        $result = ['title' => null, 'image' => null];

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Driply/1.0 (link-preview)'])
                ->get($url);

            if ($response->failed()) {
                return $result;
            }

            $html = $response->body();

            // og:title
            if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
                $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }

            // og:image
            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                $img = trim($m[1]);
                if (filter_var($img, FILTER_VALIDATE_URL)) {
                    $result['image'] = $img;
                }
            }
        } catch (\Throwable) {
            // Silencieux : on crée l'inspiration même sans métadonnées
        }

        return $result;
    }

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
