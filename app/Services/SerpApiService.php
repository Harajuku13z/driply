<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use App\Support\UnwrapGoogleUrl;
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
     * Recherche [Google Shopping API (SerpAPI)](https://serpapi.com/google-shopping-api) : prix, liens, miniatures.
     *
     * @return array<int, array{title: string, link: string, source: string, thumbnail_url: string, price: ?string, extracted_price: float|int|null, currency: ?string}>
     */
    public function googleShoppingSearch(string $query, int $limit = 8, ?string $gl = null, ?string $hl = null): array
    {
        $this->assertKey();
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $params = [
            'engine' => 'google_shopping',
            'q' => $q,
            'api_key' => $this->apiKey,
            'num' => min(max($limit, 1), 40),
        ];
        if ($gl !== null && $gl !== '') {
            $params['gl'] = $gl;
        }
        if ($hl !== null && $hl !== '') {
            $params['hl'] = $hl;
        }

        try {
            $response = Http::timeout(45)
                ->acceptJson()
                ->get($this->baseUrl.'/search', $params)
                ->throw();
        } catch (RequestException $e) {
            throw new ExternalServiceException('SerpAPI Google Shopping failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new ExternalServiceException('SerpAPI Shopping unavailable: '.$e->getMessage(), 0, $e);
        }

        $payload = $response->json();
        $rows = is_array($payload) ? ($payload['shopping_results'] ?? []) : [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $parsed = $this->parseShoppingResultRow($row);
            if ($parsed === null) {
                continue;
            }
            $out[] = $parsed;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{title: string, link: string, source: string, thumbnail_url: string, price: ?string, extracted_price: float|int|null, currency: ?string}|null
     */
    private function parseShoppingResultRow(array $row): ?array
    {
        $title = (string) ($row['title'] ?? '');
        $link = (string) ($row['link'] ?? $row['product_link'] ?? '');
        if ($title === '' && $link === '') {
            return null;
        }
        $source = (string) ($row['source'] ?? $row['store'] ?? '');
        $thumbnailUrl = (string) ($row['thumbnail'] ?? $row['serpapi_thumbnail'] ?? '');

        $priceStr = null;
        $extracted = null;
        $currency = null;
        if (isset($row['price']) && is_array($row['price'])) {
            $priceStr = isset($row['price']['value']) ? (string) $row['price']['value'] : null;
            $extracted = $row['price']['extracted_value'] ?? null;
            $currency = isset($row['price']['currency']) ? (string) $row['price']['currency'] : null;
        } elseif (isset($row['price'])) {
            $priceStr = is_scalar($row['price']) ? (string) $row['price'] : null;
        }
        $extracted = is_numeric($extracted) ? $extracted + 0 : (isset($row['extracted_price']) && is_numeric($row['extracted_price']) ? $row['extracted_price'] + 0 : null);

        return [
            'title' => $title,
            'link' => $link,
            'source' => $source,
            'thumbnail_url' => $thumbnailUrl,
            'price' => $priceStr,
            'extracted_price' => $extracted,
            'currency' => $currency,
        ];
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
        $trimmed = UnwrapGoogleUrl::unwrap(trim($url));
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
