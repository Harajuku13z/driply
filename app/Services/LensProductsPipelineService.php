<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use App\Exceptions\LensIdentificationFailedException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Flux simplifié Lens : SerpAPI `google_lens` + type=products → fallback Shopping si peu de résultats
 * → déduplication par domaine → GPT-4o pour exactement 5 offres classées.
 */
class LensProductsPipelineService
{
    private const MIN_LENS_BEFORE_SHOPPING = 5;

    private const MAX_UNIQUE_BY_DOMAIN = 15;

    /** @var list<string> */
    private const RANK_LABELS = ['Meilleur prix', 'Bon plan', 'Prix moyen', 'Haut de gamme', 'Premium'];

    public function __construct(
        private readonly SerpApiService $serpApi,
    ) {}

    /**
     * @return array{
     *     all_products: array<int, array<string, mixed>>,
     *     price_analysis: array<string, mixed>,
     *     top_3: array<int, array<string, mixed>>,
     *     top_results: array<int, array<string, mixed>>,
     *     query_used: string,
     *     item_detected: string,
     *     brand: ?string,
     *     color: ?string,
     *     price_summary: ?array<string, mixed>,
     * }
     */
    public function searchAndAnalyze(string $relativePublicPath, string $imagePublicUrl, string $currency): array
    {
        if (! Storage::disk('public')->exists($relativePublicPath)) {
            throw new LensIdentificationFailedException('Image introuvable après enregistrement.');
        }

        $hl = (string) config('driply.lens.shopping_hl', 'fr');
        $gl = (string) config('driply.lens.shopping_gl', 'fr');

        $lensData = $this->serpApi->rawGoogleLensProducts($imagePublicUrl, $hl, $gl);
        $visualMatches = $lensData['visual_matches'] ?? [];
        if (! is_array($visualMatches)) {
            $visualMatches = [];
        }

        $lensProducts = [];
        foreach ($visualMatches as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapLensVisualMatchToProduct($row);
            if ($mapped !== null) {
                $lensProducts[] = $mapped;
            }
        }

        $allProducts = $lensProducts;

        if (count($lensProducts) < self::MIN_LENS_BEFORE_SHOPPING) {
            $searchQuery = '';
            if ($visualMatches !== [] && is_array($visualMatches[0])) {
                $searchQuery = trim((string) ($visualMatches[0]['title'] ?? ''));
            }
            if ($searchQuery === '' && $lensProducts !== []) {
                $searchQuery = trim((string) ($lensProducts[0]['title'] ?? ''));
            }

            if ($searchQuery !== '') {
                $rawShopping = $this->serpApi->googleShoppingRawRows($searchQuery, 20, $gl, $hl);
                $shoppingProducts = [];
                foreach ($rawShopping as $rawRow) {
                    if (! is_array($rawRow)) {
                        continue;
                    }
                    $catalog = $this->serpApi->catalogProductFromSerpRow($rawRow);
                    if ($catalog === null) {
                        continue;
                    }
                    $ext = $catalog['extracted_price'] ?? null;
                    if (! is_numeric($ext) || (float) $ext <= 0) {
                        continue;
                    }
                    $link = (string) ($catalog['link'] ?? '');
                    if ($link === '') {
                        continue;
                    }
                    $host = $this->hostKeyFromUrl($link);
                    $sourceLabel = trim((string) ($catalog['source'] ?? ''));
                    $shoppingProducts[] = [
                        'title' => (string) ($catalog['title'] ?? ''),
                        'price' => $catalog['price'],
                        'extracted_price' => (float) $ext,
                        'currency' => (string) ($catalog['currency'] ?? 'EUR'),
                        'source' => $sourceLabel !== '' ? $sourceLabel : ($host !== '' ? $host : ''),
                        'link' => $link,
                        'thumbnail' => $catalog['thumbnail'],
                        'image' => $catalog['image'] ?? null,
                        'rating' => $catalog['rating'],
                        'reviews' => $catalog['reviews'],
                        'in_stock' => true,
                    ];
                }
                $allProducts = array_merge($lensProducts, $shoppingProducts);
            }
        }

        $unique = $this->dedupeByDomain($allProducts, self::MAX_UNIQUE_BY_DOMAIN);

        usort($unique, fn (array $a, array $b): int => (float) ($a['extracted_price'] ?? 0) <=> (float) ($b['extracted_price'] ?? 0));

        if ($unique === []) {
            throw new LensIdentificationFailedException('Aucun produit avec prix trouvé pour cette image.');
        }

        $searchQueryUsed = $this->resolveSearchQueryLabel($visualMatches, $unique);

        $gpt = $this->callOpenAiRanking($unique, $currency);
        $rawResults = $gpt['results'] ?? $gpt['top_results'] ?? [];
        if (! is_array($rawResults)) {
            $rawResults = [];
        }

        $topResults = $this->ensureFiveResults($rawResults, $unique);

        $summary = is_array($gpt['price_summary'] ?? null) ? $gpt['price_summary'] : [];
        $low = isset($summary['lowest']) && is_numeric($summary['lowest']) ? (float) $summary['lowest'] : $this->minExtracted($topResults, $unique);
        $avg = isset($summary['average']) && is_numeric($summary['average']) ? (float) $summary['average'] : $this->avgExtracted($topResults);
        $high = isset($summary['highest']) && is_numeric($summary['highest']) ? (float) $summary['highest'] : $this->maxExtracted($topResults, $unique);

        $priceSummary = [
            'lowest' => round($low, 2),
            'average' => round($avg, 2),
            'highest' => round($high, 2),
        ];

        $brand = $gpt['brand'] ?? null;
        if ($brand !== null && ! is_string($brand)) {
            $brand = null;
        }
        if ($brand === '') {
            $brand = null;
        }

        $top3 = array_slice($topResults, 0, 3);

        $priceAnalysis = [
            'item_identified' => (string) ($gpt['item_identified'] ?? ''),
            'item_type' => (string) ($gpt['item_type'] ?? $gpt['item_identified'] ?? ''),
            'brand' => $brand,
            'color' => (string) ($gpt['color'] ?? ''),
            'style' => (string) ($gpt['style'] ?? 'non déterminé'),
            'search_query_used' => $searchQueryUsed,
            'currency' => $currency,
            'confidence' => 'medium',
            'explanation' => (string) ($gpt['explanation'] ?? ''),
            'price_summary' => $priceSummary,
            'price_low' => round($low, 2),
            'price_mid' => round($avg > 0 ? $avg : $low, 2),
            'price_high' => round($high, 2),
            'suggested_resale_price' => round(max(0, ($avg > 0 ? $avg : $low) * 0.65), 2),
            'sources_analyzed' => count($unique),
            'top_results' => $topResults,
            'top_3_picks' => $top3,
        ];

        return [
            'all_products' => $unique,
            'price_analysis' => $priceAnalysis,
            'top_3' => $top3,
            'top_results' => $topResults,
            'query_used' => $searchQueryUsed,
            'item_detected' => (string) ($gpt['item_identified'] ?? ''),
            'brand' => $brand,
            'color' => (string) ($gpt['color'] ?? ''),
            'price_summary' => $priceSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function mapLensVisualMatchToProduct(array $item): ?array
    {
        $link = (string) ($item['link'] ?? '');
        if ($link === '') {
            return null;
        }

        $priceBlock = $item['price'] ?? null;
        $extracted = null;
        $priceDisplay = null;
        $currency = 'EUR';

        if (is_array($priceBlock)) {
            $priceDisplay = isset($priceBlock['value']) ? (string) $priceBlock['value'] : null;
            $ev = $priceBlock['extracted_value'] ?? null;
            $extracted = is_numeric($ev) ? (float) $ev : null;
            if (isset($priceBlock['currency'])) {
                $currency = (string) $priceBlock['currency'];
            }
        }

        if ($extracted === null || $extracted <= 0) {
            $ev2 = $item['extracted_value'] ?? $item['extracted_price'] ?? null;
            $extracted = is_numeric($ev2) ? (float) $ev2 : null;
        }

        if ($extracted === null || $extracted <= 0) {
            return null;
        }

        $thumb = $item['thumbnail'] ?? $item['thumbnail_url'] ?? null;
        $fullImg = $item['image'] ?? null;
        $rating = $item['rating'] ?? null;
        $reviews = $item['reviews'] ?? $item['reviews_count'] ?? null;

        return [
            'title' => (string) ($item['title'] ?? ''),
            'price' => $priceDisplay,
            'extracted_price' => $extracted,
            'currency' => $currency !== '' ? $currency : 'EUR',
            'source' => (string) ($item['source'] ?? ''),
            'link' => $link,
            'thumbnail' => is_string($thumb) ? $thumb : (is_scalar($thumb) ? (string) $thumb : null),
            'image' => is_string($fullImg) && $fullImg !== '' ? $fullImg : (is_scalar($fullImg) ? (string) $fullImg : null),
            'rating' => is_numeric($rating) ? (float) $rating : null,
            'reviews' => is_numeric($reviews) ? (int) $reviews : null,
            'in_stock' => filter_var($item['in_stock'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    private function dedupeByDomain(array $products, int $max): array
    {
        $seen = [];
        $unique = [];

        foreach ($products as $product) {
            $link = (string) ($product['link'] ?? '');
            $hostKey = $this->hostKeyFromUrl($link);
            if ($hostKey === '' || isset($seen[$hostKey])) {
                continue;
            }
            $seen[$hostKey] = true;
            $unique[] = $product;
            if (count($unique) >= $max) {
                break;
            }
        }

        return $unique;
    }

    private function hostKeyFromUrl(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @param  array<int, mixed>  $visualMatches
     * @param  array<int, array<string, mixed>>  $unique
     */
    private function resolveSearchQueryLabel(array $visualMatches, array $unique): string
    {
        if ($visualMatches !== [] && is_array($visualMatches[0])) {
            $t = trim((string) ($visualMatches[0]['title'] ?? ''));
            if ($t !== '') {
                return $t;
            }
        }
        if ($unique !== []) {
            $t = trim((string) ($unique[0]['title'] ?? ''));
            if ($t !== '') {
                return $t;
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $unique
     * @return array<string, mixed>
     */
    private function callOpenAiRanking(array $unique, string $currency): array
    {
        $key = (string) config('driply.openai.key', '');
        if ($key === '') {
            throw new ExternalServiceException('OpenAI API key is not configured');
        }

        $model = (string) config('driply.openai.model', 'gpt-4o');

        $json = json_encode($unique, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
Tu es un expert en mode et pricing de vêtements.

Voici des produits trouvés (Google Lens produits et/ou Google Shopping) pour cet article, triés par prix croissant — chaque domaine marchand n'apparaît qu'une fois :
{$json}

Devise de référence pour les montants numériques : {$currency}

RÈGLES STRICTES :
1. Retourne TOUJOURS exactement 5 résultats dans le tableau "results".
2. Chaque résultat doit avoir un lien différent (domaine / hôte différent).
3. Exclure tout produit sans thumbnail ou sans lien valide.
4. Les 5 rank_label dans cet ordre FIXE (une par ligne, dans l'ordre des résultats) :
   - Position 1 → "Meilleur prix"
   - Position 2 → "Bon plan"
   - Position 3 → "Prix moyen"
   - Position 4 → "Haut de gamme"
   - Position 5 → "Premium"
5. Si moins de 5 produits uniques pertinents dans la liste → réutilise les meilleures offres en variant le rank_label pour compléter à 5 (liens toujours valides).

Réponds UNIQUEMENT avec un JSON valide UTF-8, sans markdown ni backticks, structure :
{
  "item_identified": "description complète de l'article",
  "brand": "marque identifiée ou null",
  "color": "couleur exacte",
  "item_type": "type de vêtement",
  "price_summary": {
    "lowest": nombre,
    "average": nombre,
    "highest": nombre
  },
  "explanation": "2 phrases en français sur la fourchette de prix",
  "results": [
    {
      "rank_label": "Meilleur prix",
      "title": "titre exact",
      "price": 49.99,
      "price_formatted": "49,99 €",
      "source": "domaine.fr",
      "link": "https://...",
      "thumbnail": "https://...",
      "image": "https://...",
      "rating": 4.5,
      "reviews": 1200,
      "in_stock": true,
      "why_selected": "courte phrase"
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
                        ['role' => 'system', 'content' => 'Expert mode et pricing. JSON valide uniquement. Toujours 5 entrées dans results.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0,
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

        $content = preg_replace('/^```json\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', (string) $content);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ExternalServiceException('OpenAI returned non-JSON content');
        }

        return $decoded;
    }

    /**
     * @param  array<int, mixed>  $gptResults
     * @param  array<int, array<string, mixed>>  $uniqueCatalog
     * @return array<int, array<string, mixed>>
     */
    private function ensureFiveResults(array $gptResults, array $uniqueCatalog): array
    {
        $out = [];
        $seenLinks = [];

        foreach ($gptResults as $row) {
            if (! is_array($row) || count($out) >= 5) {
                break;
            }
            $normalized = $this->normalizeTopResultRow($row);
            $link = (string) ($normalized['link'] ?? '');
            $thumb = trim((string) ($normalized['thumbnail'] ?? ''));
            if ($link === '' || $thumb === '' || isset($seenLinks[$link])) {
                continue;
            }
            if (trim((string) ($normalized['image'] ?? '')) === '') {
                foreach ($uniqueCatalog as $cat) {
                    if ((string) ($cat['link'] ?? '') !== $link) {
                        continue;
                    }
                    $img = trim((string) ($cat['image'] ?? ''));
                    if ($img !== '') {
                        $normalized['image'] = $img;
                    }
                    break;
                }
            }
            $seenLinks[$link] = true;
            $normalized['rank_label'] = self::RANK_LABELS[count($out)] ?? (string) ($normalized['rank_label'] ?? '');
            $out[] = $normalized;
        }

        foreach ($uniqueCatalog as $row) {
            if (count($out) >= 5) {
                break;
            }
            $link = (string) ($row['link'] ?? '');
            $thumb = trim((string) ($row['thumbnail'] ?? ''));
            if ($link === '' || $thumb === '' || isset($seenLinks[$link])) {
                continue;
            }
            $seenLinks[$link] = true;
            $price = (float) ($row['extracted_price'] ?? 0);
            $fullImg = trim((string) ($row['image'] ?? ''));
            $out[] = $this->normalizeTopResultRow([
                'rank_label' => self::RANK_LABELS[count($out)] ?? 'Offre',
                'title' => (string) ($row['title'] ?? ''),
                'price' => $price,
                'price_formatted' => '',
                'source' => (string) ($row['source'] ?? ''),
                'link' => $link,
                'thumbnail' => $thumb,
                'image' => $fullImg,
                'rating' => $row['rating'] ?? null,
                'reviews' => $row['reviews'] ?? null,
                'in_stock' => true,
                'why_selected' => '',
            ]);
        }

        while (count($out) < 5 && $out !== []) {
            $idx = min(count($out) - 1, 0);
            $clone = $out[$idx];
            $clone['rank_label'] = self::RANK_LABELS[count($out)] ?? 'Offre';
            $clone['why_selected'] = (string) ($clone['why_selected'] ?? '');
            $out[] = $clone;
        }

        return array_slice($out, 0, 5);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeTopResultRow(array $row): array
    {
        $link = (string) ($row['link'] ?? '');
        $price = $row['price'] ?? 0;
        $priceF = is_numeric($price) ? (float) $price : 0.0;
        if ($priceF < 0.01 && isset($row['price_formatted']) && is_string($row['price_formatted'])) {
            $priceF = $this->quickParsePrice($row['price_formatted']);
        }

        return [
            'rank_label' => (string) ($row['rank_label'] ?? ''),
            'title' => (string) ($row['title'] ?? 'Sans titre'),
            'price' => $priceF,
            'price_formatted' => (string) ($row['price_formatted'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'link' => $link,
            'thumbnail' => (string) ($row['thumbnail'] ?? ''),
            'image' => (string) ($row['image'] ?? ''),
            'why_selected' => (string) ($row['why_selected'] ?? ''),
            'rating' => isset($row['rating']) && is_numeric($row['rating']) ? (float) $row['rating'] : null,
            'reviews' => isset($row['reviews']) && is_numeric($row['reviews']) ? (int) $row['reviews'] : null,
            'in_stock' => filter_var($row['in_stock'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    private function quickParsePrice(string $formatted): float
    {
        $s = Str::replace(['€', "\u{00a0}", ' '], '', $formatted);
        $s = trim(str_replace(',', '.', $s));

        return is_numeric($s) ? (float) $s : 0.0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $topResults
     * @param  array<int, array<string, mixed>>  $fallback
     */
    private function minExtracted(array $topResults, array $fallback): float
    {
        $nums = $this->pricesFromResults($topResults);
        if ($nums === []) {
            foreach ($fallback as $p) {
                if (isset($p['extracted_price']) && is_numeric($p['extracted_price'])) {
                    $nums[] = (float) $p['extracted_price'];
                }
            }
        }

        return $nums === [] ? 0.0 : min($nums);
    }

    /**
     * @param  array<int, array<string, mixed>>  $topResults
     */
    private function maxExtracted(array $topResults, array $fallback): float
    {
        $nums = $this->pricesFromResults($topResults);
        if ($nums === []) {
            foreach ($fallback as $p) {
                if (isset($p['extracted_price']) && is_numeric($p['extracted_price'])) {
                    $nums[] = (float) $p['extracted_price'];
                }
            }
        }

        return $nums === [] ? 0.0 : max($nums);
    }

    /**
     * @param  array<int, array<string, mixed>>  $topResults
     */
    private function avgExtracted(array $topResults): float
    {
        $nums = $this->pricesFromResults($topResults);

        return $nums === [] ? 0.0 : array_sum($nums) / count($nums);
    }

    /**
     * @param  array<int, array<string, mixed>>  $topResults
     * @return list<float>
     */
    private function pricesFromResults(array $topResults): array
    {
        $nums = [];
        foreach ($topResults as $r) {
            $p = $r['price'] ?? null;
            if (is_numeric($p) && (float) $p > 0) {
                $nums[] = (float) $p;
            }
        }

        return $nums;
    }
}
