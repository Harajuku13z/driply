<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PriceAnalysisService
{
    /**
     * Analyse GPT pour la liste catalogue Lens/Shopping (spec Driply : price_low / top_3_picks).
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    public function analyzeLensProductList(array $products, string $currency = 'EUR', string $searchQueryUsed = ''): array
    {
        $key = (string) config('driply.openai.key', '');
        if ($key === '') {
            throw new ExternalServiceException('OpenAI API key is not configured');
        }

        $model = (string) config('driply.openai.model', 'gpt-4o');
        $listJson = json_encode($products, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $queryLine = $searchQueryUsed !== '' ? $searchQueryUsed : '(déduite des titres produits)';
        $queryJson = json_encode($queryLine, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu es un expert en mode et pricing de vêtements.

Article recherché (requête Shopping utilisée) : {$queryJson}

Produits agrégés (Google Lens / Google Shopping), triés par prix croissant — chaque domaine marchand n’apparaît qu’une fois :
{$listJson}

RÈGLES :
- Chaque entrée de top_results doit avoir un lien différent (hôtes différents).
- Ne garde que les offres qui correspondent vraiment au même article (type, couleur, coupe). Écarte couleurs ou modèles manifestement différents.
- Entre 3 et 5 entrées dans top_results lorsque possible.

Réponds UNIQUEMENT avec un JSON valide UTF-8 (sans markdown ni backticks). Structure exacte :
{
  "item_identified": "description précise de l'article détecté",
  "brand": "marque si identifiée ou null",
  "color": "couleur exacte",
  "item_type": "type de vêtement",
  "style": "style (streetwear, casual, etc.)",
  "search_query_used": "…",
  "currency": "{$currency}",
  "confidence": "low | medium | high",
  "price_summary": {
    "lowest": nombre,
    "average": nombre,
    "highest": nombre
  },
  "explanation": "2 phrases en français sur la fourchette de prix et la variété des offres",
  "top_results": [
    {
      "rank_label": "Meilleur prix | Prix moyen | Premium | Bon plan | Prix élevé",
      "title": "titre du produit",
      "price": nombre,
      "price_formatted": "ex: 49,99 €",
      "source": "nom du site ou domaine",
      "link": "url unique",
      "thumbnail": "url image ou chaîne vide",
      "why_selected": "une courte phrase"
    }
  ]
}

Attribution rank_label : le moins cher pertinent → "Meilleur prix" ; le plus cher pertinent → "Premium" ; milieu → "Prix moyen" ; bonne note/avis → "Bon plan" si pertinent.

Le champ search_query_used doit être exactement cette chaîne JSON : {$queryJson}
PROMPT;

        try {
            $response = Http::timeout(120)
                ->withToken($key)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Tu es un expert mode et pricing. Tu réponds toujours en JSON valide uniquement, sans markdown ni texte hors JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.1,
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

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ExternalServiceException('OpenAI returned non-JSON content');
        }

        $this->assertLensV2AnalysisShape($decoded);

        $merged = $this->mergeLensV2WithLegacyFields($decoded, $searchQueryUsed, $products);

        $this->assertLensListAnalysisShape($merged);

        return $this->applyLensListNumericFixups($merged, $products);
    }

    /**
     * Étape finale : classement des offres à partir de l’article identifié par vision + catalogue filtré.
     *
     * @param  array<string, mixed>  $itemDetails  Sortie GPT vision (search_query_en, color_primary, …)
     * @param  array<int, array<string, mixed>>  $products  Produits avec éventuellement color_match
     * @return array<string, mixed>
     */
    public function finalizeLensShoppingFromVision(array $itemDetails, array $products, string $currency, string $searchQueriesLabel): array
    {
        $key = (string) config('driply.openai.key', '');
        if ($key === '') {
            throw new ExternalServiceException('OpenAI API key is not configured');
        }

        $model = (string) config('driply.openai.model', 'gpt-4o');
        $forPrompt = [];
        foreach ($products as $p) {
            if (! is_array($p)) {
                continue;
            }
            $row = $p;
            unset($row['color_match'], $row['color_confirmed']);
            $forPrompt[] = $row;
        }
        $listJson = json_encode($forPrompt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $type = (string) ($itemDetails['item_type'] ?? '');
        $brand = (string) ($itemDetails['brand'] ?? '');
        $modelName = (string) ($itemDetails['model'] ?? '');
        $color = (string) ($itemDetails['color_primary'] ?? '');
        $hex = (string) ($itemDetails['color_hex'] ?? '');
        $material = (string) ($itemDetails['material'] ?? '');
        $cut = (string) ($itemDetails['cut_style'] ?? '');
        $distinct = (string) ($itemDetails['distinctive_details'] ?? '');
        $details = $distinct !== '' ? $distinct : (string) ($itemDetails['details'] ?? '');
        $colorJson = json_encode($color, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu as analysé une image et identifié cet article avec certitude :

DESCRIPTION EXACTE DE L'ARTICLE :
- Type     : {$type}
- Marque   : {$brand}
- Modèle   : {$modelName}
- Couleur  : {$color} (hex: {$hex})
- Matière  : {$material}
- Coupe    : {$cut}
- Détails  : {$details}

Voici les produits trouvés sur Google Shopping (champs color_confirmed exclus) :
{$listJson}

RÈGLES STRICTES :
1. Retourne TOUJOURS exactement 3 résultats dans "results", ni plus ni moins.
2. Chaque résultat doit avoir un lien différent (domaine / hôte différent).
3. Priorise les titres qui mentionnent la couleur {$colorJson}.
4. Si moins de 3 ont la bonne couleur, complète avec les plus proches et mets rank_label = "Similaire" pour ces entrées AVANT normalisation serveur (tu peux utiliser Similaire pour toute ligne « proche »).
5. Après sélection, ordonne les 3 du moins cher au plus cher : résultat 1 = "Meilleur prix", 2 = "Prix moyen", 3 = "Premium" (sauf lignes Similaire que tu dois placer logiquement par prix).
6. Exclure tout produit sans thumbnail ou sans lien valide.

Réponds UNIQUEMENT avec un JSON valide UTF-8 (sans markdown ni backticks) :
{
  "item_confirmed": "description complète confirmée",
  "brand": "marque",
  "color": "couleur exacte",
  "price_summary": {
    "lowest": nombre,
    "average": nombre,
    "highest": nombre
  },
  "explanation": "2 phrases en français sur la fourchette de prix",
  "results": [
    {
      "rank_label": "Meilleur prix",
      "title": "titre",
      "price": 49.99,
      "price_formatted": "49,99 €",
      "source": "zara.com",
      "link": "https://...",
      "thumbnail": "https://...",
      "color_match": true,
      "note": "pourquoi ce résultat"
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
                        [
                            'role' => 'system',
                            'content' => 'Tu es expert mode et pricing. Tu respectes les règles strictes. Tu retournes exactement 3 entrées dans "results". JSON valide uniquement, sans markdown.',
                        ],
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

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ExternalServiceException('OpenAI returned non-JSON content');
        }

        $this->assertLensFinalizeShape($decoded);
        $decoded = $this->normalizeFinalizeToExactlyThreeOffers($decoded, $products);

        if (! is_array($decoded['results'] ?? null) || count($decoded['results']) < 1) {
            throw new ExternalServiceException('Aucune offre exploitable après analyse.');
        }

        $topResults = [];
        foreach ($decoded['results'] as $r) {
            if (! is_array($r)) {
                continue;
            }
            $link = (string) ($r['link'] ?? '');
            if ($link === '') {
                continue;
            }
            $price = $r['price'] ?? 0;
            $priceF = $this->parsePriceScalar($price);
            if ($priceF < 0.01) {
                $priceF = $this->parsePriceScalar($r['price_formatted'] ?? '');
            }
            $topResults[] = [
                'rank_label' => (string) ($r['rank_label'] ?? ''),
                'title' => (string) ($r['title'] ?? 'Sans titre'),
                'price' => $priceF,
                'price_formatted' => (string) ($r['price_formatted'] ?? ''),
                'source' => (string) ($r['source'] ?? ''),
                'link' => $link,
                'thumbnail' => (string) ($r['thumbnail'] ?? ''),
                'why_selected' => (string) ($r['note'] ?? ''),
                'color_match' => (bool) ($r['color_match'] ?? false),
            ];
        }

        $summary = is_array($decoded['price_summary'] ?? null) ? $decoded['price_summary'] : [];
        if ($summary === []) {
            $summary = ['lowest' => 0.0, 'average' => 0.0, 'highest' => 0.0];
        }

        $v2 = [
            'item_identified' => (string) ($decoded['item_confirmed'] ?? $type),
            'item_type' => $type !== '' ? $type : (string) ($decoded['item_confirmed'] ?? ''),
            'color' => (string) ($decoded['color'] ?? $color),
            'brand' => $decoded['brand'] ?? ($brand !== '' ? $brand : null),
            'price_summary' => $summary,
            'explanation' => (string) ($decoded['explanation'] ?? ''),
            'top_results' => $topResults,
            'currency' => $currency,
            'confidence' => (string) ($itemDetails['confidence'] ?? 'medium'),
            'style' => 'non déterminé',
        ];

        $this->assertLensV2AnalysisShape($v2);

        $merged = $this->mergeLensV2WithLegacyFields($v2, $searchQueriesLabel, $products);
        $this->assertLensListAnalysisShape($merged);

        $merged['vision_item'] = $itemDetails;
        $merged['model'] = (string) ($decoded['model'] ?? $modelName);

        return $this->applyLensListNumericFixups($merged, $products);
    }

    /**
     * Garantit exactement 3 offres (domaines distincts, lien + vignette), labels Meilleur prix / Prix moyen / Premium après tri par prix.
     *
     * @param  array<string, mixed>  $decoded
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private function normalizeFinalizeToExactlyThreeOffers(array $decoded, array $products): array
    {
        $candidates = [];
        $seenHosts = [];

        $pushCandidate = function (array $r) use (&$candidates, &$seenHosts): void {
            $link = trim((string) ($r['link'] ?? ''));
            $thumb = trim((string) ($r['thumbnail'] ?? ''));
            if ($link === '' || $thumb === '' || ! filter_var($link, FILTER_VALIDATE_URL)) {
                return;
            }
            $host = $this->normalizeOfferHost($link);
            if ($host === '' || isset($seenHosts[$host])) {
                return;
            }
            $seenHosts[$host] = true;
            $price = $r['price'] ?? 0;
            $priceF = $this->parsePriceScalar($price);
            if ($priceF < 0.01) {
                $priceF = $this->parsePriceScalar($r['price_formatted'] ?? '');
            }
            $candidates[] = [
                'rank_label' => (string) ($r['rank_label'] ?? ''),
                'title' => (string) ($r['title'] ?? 'Sans titre'),
                'price' => $priceF,
                'price_formatted' => (string) ($r['price_formatted'] ?? ''),
                'source' => (string) ($r['source'] ?? ''),
                'link' => $link,
                'thumbnail' => $thumb,
                'color_match' => (bool) ($r['color_match'] ?? false),
                'note' => (string) ($r['note'] ?? ''),
            ];
        };

        foreach ($decoded['results'] ?? [] as $row) {
            if (is_array($row)) {
                $pushCandidate($row);
            }
        }

        if (count($candidates) < 3) {
            foreach ($products as $p) {
                if (count($candidates) >= 3) {
                    break;
                }
                if (! is_array($p)) {
                    continue;
                }
                $link = trim((string) ($p['link'] ?? ''));
                $thumb = trim((string) ($p['thumbnail'] ?? ''));
                if ($link === '' || $thumb === '') {
                    continue;
                }
                $host = $this->normalizeOfferHost($link);
                if ($host === '' || isset($seenHosts[$host])) {
                    continue;
                }
                if (! isset($p['extracted_price']) || ! is_numeric($p['extracted_price'])) {
                    continue;
                }
                $seenHosts[$host] = true;
                $candidates[] = [
                    'rank_label' => 'Similaire',
                    'title' => (string) ($p['title'] ?? 'Sans titre'),
                    'price' => (float) $p['extracted_price'],
                    'price_formatted' => '',
                    'source' => (string) ($p['source'] ?? ''),
                    'link' => $link,
                    'thumbnail' => $thumb,
                    'color_match' => (bool) ($p['color_confirmed'] ?? $p['color_match'] ?? false),
                    'note' => 'Sélection complémentaire depuis le catalogue (prix croissant).',
                ];
            }
        }

        usort($candidates, fn (array $a, array $b): int => $a['price'] <=> $b['price']);
        $final = array_slice($candidates, 0, 3);
        $labels = ['Meilleur prix', 'Prix moyen', 'Premium'];
        foreach ($final as $i => &$row) {
            $row['rank_label'] = $labels[min($i, 2)];
        }
        unset($row);

        $decoded['results'] = $final;
        $nums = array_values(array_filter(array_map(fn (array $r): ?float => $r['price'] > 0 ? $r['price'] : null, $final)));
        if ($nums !== []) {
            sort($nums);
            $decoded['price_summary'] = [
                'lowest' => (float) min($nums),
                'average' => round(array_sum($nums) / count($nums), 2),
                'highest' => (float) max($nums),
            ];
        }

        return $decoded;
    }

    private function normalizeOfferHost(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertLensFinalizeShape(array $data): void
    {
        foreach (['item_confirmed', 'price_summary', 'results', 'explanation'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new ExternalServiceException('OpenAI finalize JSON missing key: '.$key);
            }
        }
        if (! is_array($data['price_summary'])) {
            throw new ExternalServiceException('OpenAI finalize: price_summary must be an object');
        }
        if (! is_array($data['results'])) {
            throw new ExternalServiceException('OpenAI finalize: results must be an array');
        }
    }

    /**
     * @param  array<string, mixed>  $v2
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private function mergeLensV2WithLegacyFields(array $v2, string $searchQueryUsed, array $products): array
    {
        $summary = is_array($v2['price_summary'] ?? null) ? $v2['price_summary'] : [];
        $low = isset($summary['lowest']) && is_numeric($summary['lowest']) ? (float) $summary['lowest'] : 0.0;
        $avg = isset($summary['average']) && is_numeric($summary['average']) ? (float) $summary['average'] : 0.0;
        $high = isset($summary['highest']) && is_numeric($summary['highest']) ? (float) $summary['highest'] : 0.0;

        $topResults = is_array($v2['top_results'] ?? null) ? $v2['top_results'] : [];
        $pickRows = [];
        $seenLinks = [];
        foreach ($topResults as $tr) {
            if (! is_array($tr)) {
                continue;
            }
            $link = (string) ($tr['link'] ?? '');
            if ($link === '' || isset($seenLinks[$link])) {
                continue;
            }
            $seenLinks[$link] = true;
            $price = $tr['price'] ?? 0;
            $priceF = $this->parsePriceScalar($price);
            if ($priceF < 0.01) {
                $priceF = $this->parsePriceScalar($tr['price_formatted'] ?? '');
            }
            $pickRows[] = [
                'title' => (string) ($tr['title'] ?? 'Sans titre'),
                'price' => $priceF,
                'link' => $link,
                'source' => (string) ($tr['source'] ?? ''),
                'thumbnail' => (string) ($tr['thumbnail'] ?? ''),
                'rank_label' => (string) ($tr['rank_label'] ?? ''),
                'price_formatted' => (string) ($tr['price_formatted'] ?? ''),
                'why_selected' => (string) ($tr['why_selected'] ?? ''),
            ];
            if (count($pickRows) >= 8) {
                break;
            }
        }

        $currency = (string) ($v2['currency'] ?? 'EUR');
        $avgForResale = $avg > 0 ? $avg : (($low > 0 && $high > 0) ? ($low + $high) / 2 : $low);

        return array_merge($v2, [
            'item_type' => (string) ($v2['item_type'] ?? $v2['item_identified'] ?? ''),
            'brand' => array_key_exists('brand', $v2) ? $v2['brand'] : null,
            'style' => (string) ($v2['style'] ?? 'non déterminé'),
            'color' => (string) ($v2['color'] ?? ''),
            'search_query_used' => (string) ($v2['search_query_used'] ?? $searchQueryUsed),
            'price_low' => $low,
            'price_mid' => $avg > 0 ? $avg : $low,
            'price_high' => $high,
            'currency' => $currency,
            'confidence' => (string) ($v2['confidence'] ?? 'medium'),
            'explanation' => (string) ($v2['explanation'] ?? ''),
            'suggested_resale_price' => round(max(0, $avgForResale * 0.65), 2),
            'sources_analyzed' => count($products),
            'top_3_picks' => array_slice($pickRows, 0, 3),
            'top_results' => $topResults,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertLensV2AnalysisShape(array $data): void
    {
        $required = [
            'item_identified',
            'item_type',
            'color',
            'price_summary',
            'explanation',
            'top_results',
            'currency',
        ];
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                throw new ExternalServiceException('OpenAI JSON missing key: '.$key);
            }
        }
        if (! is_array($data['price_summary'])) {
            throw new ExternalServiceException('OpenAI JSON: price_summary must be an object');
        }
        if (! is_array($data['top_results'])) {
            throw new ExternalServiceException('OpenAI JSON: top_results must be an array');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertLensListAnalysisShape(array $data): void
    {
        $required = [
            'item_type',
            'style',
            'color',
            'price_low',
            'price_mid',
            'price_high',
            'currency',
            'confidence',
            'explanation',
            'suggested_resale_price',
            'sources_analyzed',
            'top_3_picks',
        ];
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                throw new ExternalServiceException('OpenAI JSON missing key: '.$key);
            }
        }
        if (! is_array($data['top_3_picks'])) {
            throw new ExternalServiceException('OpenAI JSON: top_3_picks must be an array');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private function applyLensListNumericFixups(array $data, array $products): array
    {
        $nums = [];
        foreach ($products as $p) {
            if (isset($p['extracted_price']) && is_numeric($p['extracted_price'])) {
                $nums[] = (float) $p['extracted_price'];
            }
        }
        if ($nums !== []) {
            sort($nums);
            $low = min($nums);
            $high = max($nums);
            $mid = $nums[(int) floor((count($nums) - 1) / 2)];
            if ((float) ($data['price_mid'] ?? 0) < 1.0) {
                $data['price_low'] = round(max(0, $low * 0.9), 2);
                $data['price_mid'] = round($mid, 2);
                $data['price_high'] = round($high * 1.1, 2);
                $data['suggested_resale_price'] = round(max(0, $mid * 0.65), 2);
            }
            $data['sources_analyzed'] = max((int) ($data['sources_analyzed'] ?? 0), count($products));
        }

        $badLabels = ['', 'inconnu', 'unknown', 'n/a', 'non renseigné'];
        $it = Str::lower(trim((string) ($data['item_type'] ?? '')));
        if (in_array($it, $badLabels, true)) {
            foreach ($products as $p) {
                $t = trim((string) ($p['title'] ?? ''));
                if ($t !== '') {
                    $data['item_type'] = Str::limit($t, 140, '');
                    break;
                }
            }
        }
        if (in_array(Str::lower(trim((string) ($data['style'] ?? ''))), $badLabels, true)) {
            $data['style'] = 'non déterminé';
        }
        if (in_array(Str::lower(trim((string) ($data['color'] ?? ''))), $badLabels, true)) {
            $data['color'] = 'non déterminée';
        }

        /** @var array<int, mixed> $picks */
        $picks = $data['top_3_picks'];
        $normalizedPicks = [];
        foreach ($picks as $pick) {
            if (is_array($pick)) {
                $normalizedPicks[] = $this->normalizeTopPick($pick, $products);
            }
        }
        if (count($normalizedPicks) < 3) {
            $seenLinks = [];
            foreach ($normalizedPicks as $pk) {
                $seenLinks[(string) ($pk['link'] ?? '')] = true;
            }
            foreach ($products as $pr) {
                if (count($normalizedPicks) >= 3) {
                    break;
                }
                $link = (string) ($pr['link'] ?? '');
                if ($link === '' || isset($seenLinks[$link])) {
                    continue;
                }
                $seenLinks[$link] = true;
                $normalizedPicks[] = [
                    'title' => (string) ($pr['title'] ?? ''),
                    'price' => isset($pr['extracted_price']) && is_numeric($pr['extracted_price']) ? (float) $pr['extracted_price'] : 0.0,
                    'link' => $link,
                    'source' => (string) ($pr['source'] ?? ''),
                    'thumbnail' => (string) ($pr['thumbnail'] ?? ''),
                    'rank_label' => '',
                    'price_formatted' => '',
                    'why_selected' => '',
                ];
            }
        }
        $data['top_3_picks'] = array_slice($normalizedPicks, 0, 3);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $pick
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private function normalizeTopPick(array $pick, array $products): array
    {
        $link = (string) ($pick['link'] ?? '');
        $price = $pick['price'] ?? 0;
        $priceF = $this->parsePriceScalar($price);
        if ($priceF < 0.01) {
            $priceF = $this->parsePriceScalar($pick['price_formatted'] ?? '');
        }
        if ($link !== '') {
            foreach ($products as $pr) {
                if (($pr['link'] ?? '') === $link && isset($pr['extracted_price']) && is_numeric($pr['extracted_price']) && $priceF < 0.01) {
                    $priceF = (float) $pr['extracted_price'];
                    break;
                }
            }
        }

        return [
            'title' => (string) ($pick['title'] ?? 'Sans titre'),
            'price' => $priceF,
            'link' => $link,
            'source' => (string) ($pick['source'] ?? ''),
            'thumbnail' => (string) ($pick['thumbnail'] ?? ''),
            'rank_label' => (string) ($pick['rank_label'] ?? ''),
            'price_formatted' => (string) ($pick['price_formatted'] ?? ''),
            'why_selected' => (string) ($pick['why_selected'] ?? ''),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lensProducts
     * @return array<string, mixed>
     */
    public function analyzeFromLensResults(array $lensProducts, string $currency = 'EUR'): array
    {
        $key = (string) config('driply.openai.key', '');
        if ($key === '') {
            throw new ExternalServiceException('OpenAI API key is not configured');
        }

        $model = (string) config('driply.openai.model', 'gpt-4o');
        $envelope = [
            'lens_rows' => $this->stripInternalLensFields($lensProducts),
        ];
        $listJson = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu es un expert en mode et en valorisation de vêtements et accessoires.

Voici le JSON : "lens_rows" = pour chaque correspondance visuelle Google Lens (jusqu’à 4), les champs Lens (titre, source, liens, image, price_found si Lens l’a fourni) et "shopping_offers" = offres Google Shopping (SerpAPI) avec price, extracted_price, currency, titre marchand, lien — utilise les miniatures uniquement si tu en parles dans l’explication (les URLs sont là pour contexte marchand).

{$listJson}

Consignes :
1) Priorité aux prix : d’abord extracted_price / price dans shopping_offers, puis price_found sur Lens. Tu n’as pas accès aux pages web.
2) Si peu ou pas de prix numériques, estime une fourchette à partir des titres / marques et indique confidence "low".
3) Donne les estimations dans la devise "{$currency}" (convertis mentalement si les montants sont dans une autre devise).
4) Pour sources_analyzed, compte les entrées lens + le nombre total d’offres shopping utilisées pour raisonner.

Réponds UNIQUEMENT avec un JSON valide (sans texte avant ni après) avec cette structure exacte :
{
  "item_type": "type de vêtement ou accessoire identifié",
  "style": "style identifié (ex: casual, formel, streetwear...)",
  "color": "couleur principale identifiée",
  "estimated_price_low": nombre (prix bas en {$currency}),
  "estimated_price_mid": nombre (prix médian réaliste en {$currency}),
  "estimated_price_high": nombre (prix haut en {$currency}),
  "currency": "{$currency}",
  "confidence": "low | medium | high",
  "explanation": "explication courte de 2-3 phrases en français justifiant la fourchette de prix",
  "suggested_resale_price": nombre (prix conseillé pour revente en {$currency}),
  "sources_analyzed": nombre de sources prises en compte
}

Si les informations sont insuffisantes pour estimer un prix fiable, mets confidence = "low" et donne quand même une estimation approximative.
PROMPT;

        try {
            $response = Http::timeout(120)
                ->withToken($key)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'Tu renvoies uniquement du JSON UTF-8 valide, sans markdown.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
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

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ExternalServiceException('OpenAI returned non-JSON content');
        }

        $this->assertAnalysisShape($decoded);

        return $this->applyNumericAndLabelFixups($decoded, $lensProducts);
    }

    /**
     * Évite item_type « inconnu », prix à 0 € et sources à 0 quand des montants Shopping/Lens existent.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lensProducts
     * @return array<string, mixed>
     */
    private function applyNumericAndLabelFixups(array $data, array $lensProducts): array
    {
        $nums = [];
        foreach ($lensProducts as $p) {
            $pf = $p['price_found'] ?? null;
            if ($pf !== null && $pf !== '' && is_numeric($pf)) {
                $nums[] = (float) $pf;
            }
            foreach ($p['shopping_offers'] ?? [] as $o) {
                if (! is_array($o)) {
                    continue;
                }
                if (isset($o['extracted_price']) && is_numeric($o['extracted_price'])) {
                    $nums[] = (float) $o['extracted_price'];
                }
            }
        }

        if ($nums !== []) {
            sort($nums);
            $low = min($nums);
            $high = max($nums);
            $mid = $nums[(int) floor((count($nums) - 1) / 2)];
            $gptMid = (float) ($data['estimated_price_mid'] ?? 0);
            if ($gptMid < 1.0) {
                $data['estimated_price_low'] = round(max(0, $low * 0.9), 2);
                $data['estimated_price_mid'] = round($mid, 2);
                $data['estimated_price_high'] = round($high * 1.1, 2);
                $data['suggested_resale_price'] = round(max(0, $mid * 0.65), 2);
            }
            $data['sources_analyzed'] = max((int) ($data['sources_analyzed'] ?? 0), count($nums));
        }

        $badLabels = ['', 'inconnu', 'unknown', 'n/a', 'non renseigné'];
        $it = Str::lower(trim((string) ($data['item_type'] ?? '')));
        if (in_array($it, $badLabels, true)) {
            foreach ($lensProducts as $p) {
                $t = trim((string) ($p['title'] ?? ''));
                if ($t !== '') {
                    $data['item_type'] = Str::limit($t, 140, '');
                    break;
                }
            }
            foreach ($lensProducts as $p) {
                foreach ($p['shopping_offers'] ?? [] as $o) {
                    if (! is_array($o)) {
                        continue;
                    }
                    $t = trim((string) ($o['title'] ?? ''));
                    if ($t !== '') {
                        $data['item_type'] = Str::limit($t, 140, '');

                        break 2;
                    }
                }
            }
        }

        if (in_array(Str::lower(trim((string) ($data['style'] ?? ''))), $badLabels, true)) {
            $data['style'] = 'non déterminé';
        }
        if (in_array(Str::lower(trim((string) ($data['color'] ?? ''))), $badLabels, true)) {
            $data['color'] = 'non déterminée';
        }

        if ($nums === [] && (float) ($data['estimated_price_mid'] ?? 0) < 1.0) {
            $ex = trim((string) ($data['explanation'] ?? ''));
            if ($ex === '' || Str::contains(Str::lower($ex), ['aucune donnée'])) {
                $data['explanation'] = 'SerpAPI ou Google Shopping n’a pas renvoyé de prix exploitables. Vérifie APP_URL, `php artisan storage:link`, et DRIPLY_LENS_PUBLIC_STORAGE_BASE_URL (URL HTTPS publique vers ton dossier storage).';
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertAnalysisShape(array $data): void
    {
        $required = [
            'item_type',
            'style',
            'color',
            'estimated_price_low',
            'estimated_price_mid',
            'estimated_price_high',
            'currency',
            'confidence',
            'explanation',
            'suggested_resale_price',
            'sources_analyzed',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                throw new ExternalServiceException('OpenAI JSON missing key: '.$key);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lensProducts
     * @return array<int, array<string, mixed>>
     */
    private function stripInternalLensFields(array $lensProducts): array
    {
        return array_values(array_map(function (array $p): array {
            $offers = [];
            foreach ($p['shopping_offers'] ?? [] as $o) {
                if (! is_array($o)) {
                    continue;
                }
                $offers[] = [
                    'title' => (string) ($o['title'] ?? ''),
                    'link' => (string) ($o['link'] ?? ''),
                    'source' => (string) ($o['source'] ?? ''),
                    'thumbnail_url' => (string) ($o['thumbnail_url'] ?? ''),
                    'price' => $o['price'] ?? null,
                    'extracted_price' => $o['extracted_price'] ?? null,
                    'currency' => $o['currency'] ?? null,
                ];
            }

            return [
                'title' => (string) ($p['title'] ?? ''),
                'source' => (string) ($p['source'] ?? ''),
                'product_url' => (string) ($p['product_url'] ?? ''),
                'image_url' => (string) ($p['image_url'] ?? ''),
                'thumbnail_url' => (string) ($p['thumbnail_url'] ?? ''),
                'price_found' => $p['price_found'] ?? null,
                'currency_found' => $p['currency_found'] ?? null,
                'shopping_offers' => $offers,
            ];
        }, $lensProducts));
    }

    /**
     * Interprète un prix JSON hétérogène (nombre, chaîne "49,99 €", "1.234,56", etc.).
     */
    private function parsePriceScalar(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $s = trim($value);
            if ($s === '') {
                return 0.0;
            }
            if (is_numeric($s)) {
                return (float) $s;
            }
            $s = str_replace(["\xc2\xa0", ' '], '', $s);
            $s = str_ireplace(['€', 'eur', '$', 'usd'], '', $s);
            $s = trim($s);
            if ($s === '') {
                return 0.0;
            }
            $lastComma = strrpos($s, ',');
            $lastDot = strrpos($s, '.');
            if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
            if (is_numeric($s)) {
                return (float) $s;
            }
        }

        return 0.0;
    }
}
