<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SerpApiService
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('driply.serpapi.key', '');
        $this->baseUrl = rtrim((string) config('driply.serpapi.base_url', 'https://serpapi.com'), '/');
    }

    /**
     * @return array<int, array{thumbnail_url: string, full_url: string, source_url: string, title: string}>
     *
     * @throws ExternalServiceException
     */
    public function searchImages(string $query, int $num = 20): array
    {
        $this->assertKey();

        try {
            $response = Http::timeout(45)
                ->acceptJson()
                ->get($this->baseUrl.'/search', [
                    'engine' => 'google_images',
                    'q' => $query,
                    'api_key' => $this->apiKey,
                    'num' => min($num, 100),
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new ExternalServiceException('SerpAPI image search failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new ExternalServiceException('SerpAPI unavailable: '.$e->getMessage(), 0, $e);
        }

        $payload = $response->json();
        $images = is_array($payload) ? ($payload['images_results'] ?? []) : [];

        $formatted = [];
        foreach ($images as $row) {
            if (! is_array($row)) {
                continue;
            }
            $thumbnail = (string) ($row['thumbnail'] ?? $row['original'] ?? '');
            $full = (string) ($row['original'] ?? $row['link'] ?? '');
            $source = (string) ($row['link'] ?? $row['source'] ?? '');
            $title = (string) ($row['title'] ?? '');

            if ($full === '') {
                continue;
            }

            $formatted[] = [
                'thumbnail_url' => $thumbnail !== '' ? $thumbnail : $full,
                'full_url' => $full,
                'source_url' => $source,
                'title' => $title,
            ];
        }

        return $formatted;
    }

    /**
     * @throws ExternalServiceException
     */
    public function downloadAndStore(string $imageUrl, string $disk = 'public'): string
    {
        $sanitized = $this->sanitizeRemoteUrl($imageUrl);

        try {
            $binary = Http::timeout(60)->withHeaders(['User-Agent' => 'Driply/1.0'])->get($sanitized)->throw()->body();
        } catch (Throwable $e) {
            throw new ExternalServiceException('Could not download image: '.$e->getMessage(), 0, $e);
        }

        $ext = pathinfo((string) parse_url($sanitized, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $ext = Str::lower(Str::limit($ext, 8, ''));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $path = 'serpapi/'.Str::uuid()->toString().'.'.$ext;
        Storage::disk($disk)->put($path, $binary);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function rawLensResponse(string $imagePublicUrl): array
    {
        $this->assertKey();
        $sanitized = $this->sanitizeRemoteUrl($imagePublicUrl);

        try {
            $response = Http::timeout(90)
                ->acceptJson()
                ->get($this->baseUrl.'/search', [
                    'engine' => 'google_lens',
                    'url' => $sanitized,
                    'api_key' => $this->apiKey,
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new ExternalServiceException('SerpAPI Google Lens failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new ExternalServiceException('SerpAPI Lens unavailable: '.$e->getMessage(), 0, $e);
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new ExternalServiceException('Invalid SerpAPI Lens response');
        }

        return $decoded;
    }

    /**
     * @throws ExternalServiceException
     */
    private function assertKey(): void
    {
        if ($this->apiKey === '') {
            throw new ExternalServiceException('SerpAPI key is not configured');
        }
    }

    /**
     * @throws ExternalServiceException
     */
    private function sanitizeRemoteUrl(string $url): string
    {
        $trimmed = trim($url);
        if (! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new ExternalServiceException('Invalid remote URL');
        }

        $parts = parse_url($trimmed);
        $scheme = isset($parts['scheme']) ? Str::lower((string) $parts['scheme']) : '';

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ExternalServiceException('URL scheme not allowed');
        }

        return $trimmed;
    }
}
