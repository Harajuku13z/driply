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

        $downloadUrl = (string) ($json['download_url'] ?? $json['url'] ?? '');
        if ($downloadUrl === '') {
            throw new ExternalServiceException('Fast Server did not return a download URL');
        }

        $downloadUrl = $this->sanitizeUrl($downloadUrl);
        $thumb = isset($json['thumbnail_url']) ? $this->sanitizeUrlOptional((string) $json['thumbnail_url']) : null;
        $title = isset($json['title']) ? (string) $json['title'] : null;
        $duration = isset($json['duration']) ? (int) $json['duration'] : (isset($json['duration_seconds']) ? (int) $json['duration_seconds'] : null);
        $type = Str::lower((string) ($json['type'] ?? 'video'));
        if (! in_array($type, ['image', 'video'], true)) {
            $type = 'video';
        }

        return [
            'download_url' => $downloadUrl,
            'thumbnail_url' => $thumb,
            'title' => $title,
            'duration' => $duration,
            'type' => $type,
        ];
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
