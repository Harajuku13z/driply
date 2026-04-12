<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FastServerService
{
    /**
     * @return array{download_url: string, thumbnail_url: string|null, title: string|null, duration: int|null, type: string}
     */
    public function fetchMedia(string $url, string $platform): array
    {
        $base = rtrim((string) config('driply.fastserver.url', ''), '/');
        $key = (string) config('driply.fastserver.key', '');

        if ($base === '') {
            throw new ExternalServiceException('Fast Server URL is not configured');
        }

        $sanitized = $this->sanitizeUrl($url);

        if ($this->isFastSaverApiHost($base)) {
            return $this->fetchViaFastSaverApi($base, $sanitized, $key);
        }

        try {
            $response = Http::timeout(60)
                ->withHeaders(array_filter([
                    'Authorization' => $key !== '' ? 'Bearer '.$key : null,
                    'X-API-Key' => $key !== '' ? $key : null,
                ]))
                ->acceptJson()
                ->post($base.'/media/fetch', [
                    'url' => $sanitized,
                    'platform' => $platform,
                ]);

            if ($response->failed()) {
                $response->throw();
            }
        } catch (RequestException $e) {
            throw new ExternalServiceException('Fast Server request failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new ExternalServiceException('Fast Server unavailable: '.$e->getMessage(), 0, $e);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ExternalServiceException('Invalid Fast Server response');
        }

        return $this->normalizeFetchPayload($json);
    }

    /**
     * FastSaverAPI : GET /get-info?url=&token= (voir https://fastsaverapi.com/docs ).
     */
    private function isFastSaverApiHost(string $base): bool
    {
        $host = (string) parse_url($base, PHP_URL_HOST);

        return $host !== '' && str_contains(Str::lower($host), 'fastsaverapi.com');
    }

    private function fetchViaFastSaverApi(string $base, string $sanitizedUrl, string $token): array
    {
        if ($token === '') {
            throw new ExternalServiceException('FastSaverAPI token (FASTSERVER_KEY) is not configured');
        }

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->get($base.'/get-info', [
                    'url' => $sanitizedUrl,
                    'token' => $token,
                ]);

            if ($response->failed()) {
                $response->throw();
            }
        } catch (RequestException $e) {
            throw new ExternalServiceException('FastSaverAPI request failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new ExternalServiceException('FastSaverAPI unavailable: '.$e->getMessage(), 0, $e);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ExternalServiceException('Invalid FastSaverAPI response');
        }

        // Log la réponse brute pour diagnostiquer les carrousels manquants
        Log::channel('single')->info('FastSaverAPI raw response', [
            'keys' => array_keys($json),
            'type' => $json['type'] ?? null,
            'has_urls_list' => isset($json['urls_list']),
            'has_medias' => isset($json['medias']),
            'has_images' => isset($json['images']),
            'has_items' => isset($json['items']),
            'raw_truncated' => json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);

        if (($json['error'] ?? false) === true) {
            $msg = (string) ($json['message'] ?? $json['detail'] ?? 'FastSaverAPI error');

            throw new ExternalServiceException($msg);
        }

        return $this->normalizeFetchPayload($json);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{download_url: string, thumbnail_url: string|null, title: string|null, duration: int|null, type: string, additional_images: list<string>}
     */
    private function normalizeFetchPayload(array $json): array
    {
        $downloadUrl = (string) ($json['download_url'] ?? $json['url'] ?? '');
        if ($downloadUrl === '') {
            throw new ExternalServiceException('Fast Server did not return a download URL');
        }

        $downloadUrl = $this->sanitizeUrl($downloadUrl);
        $thumbRaw = $json['thumbnail_url'] ?? $json['thumb'] ?? null;
        $thumb = is_string($thumbRaw) ? $this->sanitizeUrlOptional($thumbRaw) : null;
        $titleRaw = $json['title'] ?? $json['caption'] ?? null;
        $title = is_string($titleRaw) ? $titleRaw : null;
        $duration = isset($json['duration']) ? (int) $json['duration'] : (isset($json['duration_seconds']) ? (int) $json['duration_seconds'] : null);
        $type = Str::lower((string) ($json['type'] ?? 'video'));
        if (! in_array($type, ['image', 'video'], true)) {
            $type = 'video';
        }

        // Carrousels Instagram / multi-images : chercher les clés communes selon les APIs
        $additionalImages = $this->extractAdditionalImages($json, $downloadUrl);

        return [
            'download_url' => $downloadUrl,
            'thumbnail_url' => $thumb,
            'title' => $title,
            'duration' => $duration,
            'type' => $type,
            'additional_images' => $additionalImages,
        ];
    }

    /**
     * Extrait les images/vidéos supplémentaires d'un carrousel (Instagram, TikTok, etc.).
     *
     * Stratégie en 3 passes :
     * 1. Chercher dans les clés connues (images, urls_list, medias, etc.)
     * 2. Scan récursif de tout le JSON pour les tableaux d'objets avec URL
     * 3. Scan récursif pour tout tableau de strings ressemblant à des URLs HTTP
     *
     * @return list<string>
     */
    private function extractAdditionalImages(array $json, string $primaryDownloadUrl): array
    {
        $candidates = [];
        $seen = [$primaryDownloadUrl => true];

        $addCandidate = function (string $url) use (&$candidates, &$seen): void {
            $sanitized = $this->sanitizeUrlOptional($url);
            if ($sanitized === null || isset($seen[$sanitized])) {
                return;
            }
            $seen[$sanitized] = true;
            $candidates[] = $sanitized;
        };

        // ── Passe 1 : clés connues au premier niveau ──
        $listKeys = [
            'urls_list', 'medias', 'images', 'media_urls', 'media_items', 'items',
            'gallery', 'urls', 'slides', 'carousel_media', 'photos', 'pictures',
            'resources', 'carousel', 'media', 'download_urls', 'video_urls',
        ];

        foreach ($listKeys as $key) {
            $raw = $json[$key] ?? null;
            if (! is_array($raw) || empty($raw)) {
                continue;
            }
            $this->extractUrlsFromArray($raw, $addCandidate);
        }

        if (! empty($candidates)) {
            return $candidates;
        }

        // ── Passe 2 : scan récursif de toutes les valeurs ──
        $this->deepScanForUrls($json, $addCandidate, 0);

        return $candidates;
    }

    /**
     * Extrait des URLs depuis un tableau (strings directes ou objets avec clé url).
     */
    private function extractUrlsFromArray(array $items, callable $addCandidate): void
    {
        $urlFields = ['download_url', 'url', 'image_url', 'src', 'thumbnail_url',
            'thumbnail', 'video_url', 'media_url', 'href', 'link', 'image'];

        foreach ($items as $item) {
            if (is_string($item) && $this->looksLikeMediaUrl($item)) {
                $addCandidate($item);
            } elseif (is_array($item)) {
                foreach ($urlFields as $field) {
                    if (isset($item[$field]) && is_string($item[$field]) && $this->looksLikeMediaUrl($item[$field])) {
                        $addCandidate($item[$field]);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Scan récursif : cherche des tableaux d'URLs ou d'objets contenant des URLs.
     */
    private function deepScanForUrls(array $data, callable $addCandidate, int $depth): void
    {
        if ($depth > 5) {
            return;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Si c'est un tableau séquentiel (liste), essayer d'en extraire des URLs
                if (isset($value[0])) {
                    $this->extractUrlsFromArray($value, $addCandidate);
                }
                $this->deepScanForUrls($value, $addCandidate, $depth + 1);
            }
        }
    }

    private function looksLikeMediaUrl(string $val): bool
    {
        $trimmed = trim($val);

        return $trimmed !== ''
            && (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://'))
            && strlen($trimmed) > 20;
    }

    /**
     * @throws ExternalServiceException
     */
    public function downloadMedia(string $downloadUrl, string $filename, string $disk = 'media'): string
    {
        $sanitized = $this->sanitizeUrl($downloadUrl);

        try {
            $binary = Http::timeout(300)->withHeaders(['User-Agent' => 'Driply/1.0'])->get($sanitized)->throw()->body();
        } catch (Throwable $e) {
            throw new ExternalServiceException('Could not download media: '.$e->getMessage(), 0, $e);
        }

        $path = trim($filename, '/');
        Storage::disk($disk)->put($path, $binary);

        return $path;
    }

    /**
     * @throws ExternalServiceException
     */
    private function sanitizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new ExternalServiceException('Invalid media URL');
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? Str::lower($scheme) : '';
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ExternalServiceException('URL scheme not allowed');
        }

        return $trimmed;
    }

    private function sanitizeUrlOptional(string $url): ?string
    {
        try {
            return $this->sanitizeUrl($url);
        } catch (ExternalServiceException) {
            return null;
        }
    }
}
