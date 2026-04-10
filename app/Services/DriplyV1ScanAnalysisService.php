<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Analyse GPT-4o : exactement 5 offres avec libellés de rang fixes (spec API v1).
 */
final class DriplyV1ScanAnalysisService
{
    private const RANK_LABELS = [
        'Meilleur prix',
        'Bon plan',
        'Prix moyen',
        'Haut de gamme',
        'Premium',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array{
     *   item_type: string,
     *   brand: ?string,
     *   color: ?string,
     *   scan_results: list<array{rank_label: string, title: string, price: float, price_formatted: string, source: string, link: string, thumbnail: string, in_stock: bool}>,
     *   scan_price_summary: array{lowest: float, average: float, highest: float}
     * }
     */
    public function analyze(array $products, string $currency = 'EUR'): array
    {
        $key = (string) config('driply.openai.key', env('OPENAI_API_KEY', ''));
        if ($key === '') {
            throw new ExternalServiceException('OpenAI API key is not configured');
        }

        $model = (string) config('driply.openai.model', env('OPENAI_MODEL', 'gpt-4o'));
        $listJson = json_encode(array_values($products), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $labelsJson = json_encode(self::RANK_LABELS, JSON_THROW_ON_ERROR);

        $prompt = <<<PROMPT
Tu es un expert mode et pricing. Produits agrégés (Google Lens / Shopping) :
{$listJson}

RÈGLES :
- Retourne EXACTEMENT 5 entrées dans "results", dans cet ordre de rank_label : {$labelsJson}
- Chaque rank_label doit être exactement l'une de ces 5 chaînes, dans l'ordre indiqué (index 0 = Meilleur prix, … index 4 = Premium).
- Chaque résultat : lien unique (hôte différent si possible), thumbnail non vide si disponible dans les données.
- Complète avec les meilleures offres disponibles ; si peu de données, duplique des offres proches en ajustant le libellé sémantique du titre mais garde les 5 rank_label fixes.
- price = nombre décimal, price_formatted en français avec devise {$currency}.

Réponds UNIQUEMENT avec un JSON valide :
{
  "item_type": "string",
  "brand": "string ou null",
  "color": "string ou null",
  "price_summary": { "lowest": 0, "average": 0, "highest": 0 },
  "results": [
    {
      "rank_label": "Meilleur prix",
      "title": "",
      "price": 0,
      "price_formatted": "",
      "source": "",
      "link": "",
      "thumbnail": "",
      "in_stock": true
    }
  ]
}
PROMPT;

        try {
            $response = Http::timeout(120)
                ->withToken($key)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Tu réponds uniquement en JSON UTF-8 valide, sans markdown.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.15,
                    'response_format' => ['type' => 'json_object'],
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new ExternalServiceException('OpenAI request failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new ExternalServiceException('OpenAI unavailable: '.$e->getMessage(), 0, $e);
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || $content === '') {
            throw new ExternalServiceException('Invalid OpenAI response');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $this->normalizeToFive($decoded, $products, $currency);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<int, array<string, mixed>>  $fallbackProducts
     * @return array{item_type: string, brand: ?string, color: ?string, scan_results: list<array<string, mixed>>, scan_price_summary: array{lowest: float, average: float, highest: float}}
     */
    private function normalizeToFive(array $decoded, array $fallbackProducts, string $currency): array
    {
        $itemType = (string) ($decoded['item_type'] ?? 'Article');
        $brand = isset($decoded['brand']) && is_string($decoded['brand']) ? $decoded['brand'] : null;
        $color = isset($decoded['color']) && is_string($decoded['color']) ? $decoded['color'] : null;

        $summary = is_array($decoded['price_summary'] ?? null) ? $decoded['price_summary'] : [];
        $low = (float) ($summary['lowest'] ?? 0);
        $avg = (float) ($summary['average'] ?? 0);
        $high = (float) ($summary['highest'] ?? 0);

        $rawResults = $decoded['results'] ?? [];
        $rows = [];
        if (is_array($rawResults)) {
            foreach ($rawResults as $r) {
                if (is_array($r)) {
                    $rows[] = $r;
                }
            }
        }

        $out = [];
        for ($i = 0; $i < 5; $i++) {
            $label = self::RANK_LABELS[$i];
            $src = $rows[$i] ?? $rows[0] ?? $this->rowFromFallback($fallbackProducts, $i);
            $out[] = [
                'rank_label' => $label,
                'title' => (string) ($src['title'] ?? 'Sans titre'),
                'price' => $this->num($src['price'] ?? 0),
                'price_formatted' => (string) ($src['price_formatted'] ?? ''),
                'source' => (string) ($src['source'] ?? ''),
                'link' => (string) ($src['link'] ?? ''),
                'thumbnail' => (string) ($src['thumbnail'] ?? ''),
                'in_stock' => (bool) ($src['in_stock'] ?? true),
            ];
        }

        return [
            'item_type' => $itemType,
            'brand' => $brand,
            'color' => $color,
            'scan_results' => $out,
            'scan_price_summary' => [
                'lowest' => $low,
                'average' => $avg > 0 ? $avg : ($low + $high) / 2,
                'highest' => $high,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fallbackProducts
     * @return array<string, mixed>
     */
    private function rowFromFallback(array $fallbackProducts, int $index): array
    {
        $p = $fallbackProducts[$index % max(count($fallbackProducts), 1)] ?? [];

        return [
            'title' => (string) ($p['title'] ?? 'Produit'),
            'price' => $p['extracted_price'] ?? $p['price'] ?? 0,
            'price_formatted' => (string) ($p['price'] ?? ''),
            'source' => (string) ($p['source'] ?? ''),
            'link' => (string) ($p['link'] ?? ''),
            'thumbnail' => (string) ($p['thumbnail'] ?? $p['thumbnail_url'] ?? ''),
            'in_stock' => true,
        ];
    }

    private function num(mixed $v): float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }

        return 0.0;
    }
}
