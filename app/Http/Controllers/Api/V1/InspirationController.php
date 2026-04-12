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

            // ── Carrousel : télécharger les images supplémentaires (max 20) ──
            foreach (array_slice($fetch['additional_images'] ?? [], 0, 20) as $extraUrl) {
                try {
                    $ext = Str::lower(pathinfo(parse_url($extraUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                    $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $ext : 'jpg';
                    $extraPath = 'imports/extra-'.Str::uuid()->toString().'.'.$ext;
                    $storedExtra = $fastServer->downloadMedia($extraUrl, $extraPath, $disk);
                    $additionalImages[] = LensPublicImageUrl::absoluteFromPublicDiskPath($storedExtra);
                } catch (\Throwable) {
                    // Garder l'URL externe si le téléchargement échoue
                    $additionalImages[] = $extraUrl;
                }
            }
        } else {
            // ── Lien web normal : récupérer métadonnées OG + prix + télécharger image ──
            $og = $this->fetchOpenGraphMeta($data['url']);
            $title = $og['title'];

            // Télécharger l'image OG en local
            if (! empty($og['image'])) {
                try {
                    $imgPath = 'imports/og-'.Str::uuid()->toString().'.jpg';
                    $imgBin = Http::timeout(15)
                        ->withHeaders(['User-Agent' => 'Driply/1.0 (link-preview)'])
                        ->get($og['image'])->throw()->body();
                    Storage::disk('public')->put($imgPath, $imgBin);
                    $thumbUrl = LensPublicImageUrl::absoluteFromPublicDiskPath($imgPath);
                    $mediaUrl = $thumbUrl;
                } catch (\Throwable) {
                    $thumbUrl = $og['image'];
                }
            }

            // Favicon de secours quand il n'y a pas d'image OG
            if (empty($thumbUrl)) {
                $faviconUrl = $this->fetchFaviconUrl($data['url']);
            }

            // Note : prix (uniquement si données structurées fiables) + lien source
            $noteParts = [];
            if (! empty($og['price'])) {
                $noteParts[] = 'Prix : '.$og['price'].' €';
            }
            $noteParts[] = $data['url'];
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

    /**
     * Récupère titre + image OG + prix d'une page web.
     *
     * @return array{title: string|null, image: string|null, price: string|null}
     */
    private function fetchOpenGraphMeta(string $url): array
    {
        $result = ['title' => null, 'image' => null, 'price' => null];

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Driply/1.0 (link-preview)'])
                ->get($url);

            if ($response->failed()) {
                return $result;
            }

            $html = $response->body();

            // ── og:title / <title> ──
            if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/', $html, $m)) {
                $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
                $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }

            // ── og:image ──
            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
                $img = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                if (filter_var($img, FILTER_VALIDATE_URL)) {
                    $result['image'] = $img;
                }
            } elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/', $html, $m)) {
                $img = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                if (filter_var($img, FILTER_VALIDATE_URL)) {
                    $result['image'] = $img;
                }
            }

            // ── Prix : extraction multi-sources ──
            $result['price'] = $this->extractPriceFromHtml($html);
        } catch (\Throwable) {
            // Silencieux : on crée l'inspiration même sans métadonnées
        }

        return $result;
    }

    /**
     * Extrait un prix depuis le HTML d'une page produit.
     * Ordre de priorité :
     * 1. JSON-LD (schema.org Product / Offer)
     * 2. Meta tags (product:price:amount, og:price:amount)
     * 3. Attributs data courants (data-price, itemprop="price")
     * 4. Regex sur patterns de prix visibles
     */
    private function extractPriceFromHtml(string $html): ?string
    {
        // ── 1. JSON-LD ──
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $ldMatches)) {
            foreach ($ldMatches[1] as $ldRaw) {
                $ld = json_decode(trim($ldRaw), true);
                if (! is_array($ld)) {
                    continue;
                }
                $price = $this->extractPriceFromJsonLd($ld);
                if ($price !== null) {
                    return $price;
                }
            }
        }

        // ── 2. Meta tags : product:price:amount / og:price:amount ──
        $metaPricePatterns = [
            'product:price:amount',
            'og:price:amount',
        ];
        foreach ($metaPricePatterns as $prop) {
            $escaped = preg_quote($prop, '/');
            if (preg_match('/<meta[^>]+(?:property|name)=["\']'.$escaped.'["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)
                || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']'.$escaped.'["\']/', $html, $m)) {
                $val = $this->sanitizePrice(trim($m[1]));
                if ($val !== null) {
                    return $val;
                }
            }
        }

        // ── 3. itemprop="price" ──
        if (preg_match('/<[^>]+itemprop=["\']price["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)
            || preg_match('/<[^>]+itemprop=["\']price["\'][^>]*>\s*([\d.,]+)/', $html, $m)) {
            $val = $this->sanitizePrice(trim($m[1]));
            if ($val !== null) {
                return $val;
            }
        }

        // Les étapes 4 (data-price) et 5 (regex visibles) sont volontairement omises :
        // trop de faux positifs (prix de navigation, produits liés, etc.).
        // Seules les données structurées (JSON-LD, meta tags, itemprop) sont fiables.

        return null;
    }

    /**
     * Parcourt récursivement le JSON-LD pour trouver un prix dans Product / Offer.
     */
    private function extractPriceFromJsonLd(array $ld): ?string
    {
        // Tableau de schemas
        if (isset($ld[0]) && is_array($ld[0])) {
            foreach ($ld as $item) {
                if (is_array($item)) {
                    $price = $this->extractPriceFromJsonLd($item);
                    if ($price !== null) {
                        return $price;
                    }
                }
            }

            return null;
        }

        $type = $ld['@type'] ?? '';
        $types = is_array($type) ? $type : [$type];

        // Offre directe
        if (array_intersect($types, ['Offer', 'AggregateOffer'])) {
            $p = $ld['price'] ?? $ld['lowPrice'] ?? $ld['highPrice'] ?? null;
            if ($p !== null) {
                return $this->sanitizePrice((string) $p);
            }
        }

        // Product avec offers
        if (array_intersect($types, ['Product', 'IndividualProduct', 'ProductModel'])) {
            $offers = $ld['offers'] ?? null;
            if (is_array($offers)) {
                if (isset($offers['price']) || isset($offers['lowPrice'])) {
                    $p = $offers['price'] ?? $offers['lowPrice'] ?? null;
                    if ($p !== null) {
                        return $this->sanitizePrice((string) $p);
                    }
                }
                // Tableau d'offres
                if (isset($offers[0]) && is_array($offers[0])) {
                    $p = $offers[0]['price'] ?? $offers[0]['lowPrice'] ?? null;
                    if ($p !== null) {
                        return $this->sanitizePrice((string) $p);
                    }
                }
            }
        }

        // @graph (schema.org imbriqué)
        if (isset($ld['@graph']) && is_array($ld['@graph'])) {
            foreach ($ld['@graph'] as $node) {
                if (is_array($node)) {
                    $price = $this->extractPriceFromJsonLd($node);
                    if ($price !== null) {
                        return $price;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Nettoie une chaîne prix : "29.99" / "29,99" / "29.99 EUR" → "29.99"
     */
    private function sanitizePrice(string $raw): ?string
    {
        $clean = preg_replace('/[^\d.,]/', '', $raw);
        if ($clean === null || $clean === '') {
            return null;
        }
        // Normaliser : virgule → point
        $clean = str_replace(',', '.', $clean);
        $val = (float) $clean;

        return $val > 0 ? number_format($val, 2, '.', '') : null;
    }

    private function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : 'Lien partagé';
    }

    /**
     * Tente de récupérer l'URL du favicon d'un site web.
     * Ordre : <link rel="icon"> dans le HTML → /favicon.ico
     */
    private function fetchFaviconUrl(string $pageUrl): ?string
    {
        try {
            $parsed = parse_url($pageUrl);
            if (! isset($parsed['scheme'], $parsed['host'])) {
                return null;
            }
            $origin = $parsed['scheme'].'://'.$parsed['host'];

            // 1. Chercher dans le HTML
            $html = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Driply/1.0 (link-preview)'])
                ->get($pageUrl)
                ->body();

            if (preg_match('/<link[^>]+rel=["\'](?:shortcut )?icon["\'][^>]+href=["\']([^"\']+)["\']/', $html, $m)
                || preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'](?:shortcut )?icon["\']/', $html, $m)) {
                $href = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                if (str_starts_with($href, 'http')) {
                    return $href;
                }
                // Relative URL
                return $origin.'/'.ltrim($href, '/');
            }

            // 2. Fallback /favicon.ico
            $faviconUrl = $origin.'/favicon.ico';
            $resp = Http::timeout(8)->head($faviconUrl);
            if ($resp->successful()) {
                return $faviconUrl;
            }
        } catch (\Throwable) {
        }

        return null;
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
