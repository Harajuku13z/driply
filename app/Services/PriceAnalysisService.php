<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class PriceAnalysisService
{
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

        return $decoded;
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
}
