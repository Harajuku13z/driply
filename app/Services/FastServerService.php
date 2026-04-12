<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
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
     * Extrait les images supplémentaires d'un carrousel (Instagram, etc.).
     * Parcourt les clés communes utilisées par FastSaverAPI et les custom fast servers.
     *
     * @return list<string>
     */
    private function extractAdditionalImages(array $json, string $primaryDownloadUrl): array
    {
        $candidates = [];

        // Clés courantes renvoyées par différentes APIs de téléchargement social
        $listKeys = ['images', 'media_urls', 'media_items', 'items', 'gallery', 'urls', 'slides', 'carousel_media'];

        foreach ($listKeys as $key) {
            $raw = $json[$key] ?? null;
            if (! is_array($raw) || empty($raw)) {
                continue;
            }

            foreach ($raw as $item) {
                $url = null;

                if (is_string($item)) {
                    $url = $item;
                } elseif (is_array($item)) {
                    // Objet avec download_url / url / image_url / src / thumbnail
                    foreach (['download_url', 'url', 'image_url', 'src', 'thumbnail_url', 'thumbnail'] as $field) {
                        if (isset($item[$field]) && is_string($item[$field]) && $item[$field] !== '') {
                            $url = $item[$field];
                            break;
                        }
                    }
                }

                if ($url !== null) {
                    $sanitized = $this->sanitizeUrlOptional($url);
                    if ($sanitized !== null && $sanitized !== $primaryDownloadUrl) {
                        $candidates[] = $sanitized;
                    }
                }
            }

            // Arrêter à la première clé qui a produit des résultats
            if (! empty($candidates)) {
                break;
            }
        }

        // Dédupliquer en préservant l'ordre
        return array_values(array_unique($candidates));
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
