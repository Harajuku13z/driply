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
        $listJson = json_encode($lensProducts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu es un expert en mode et en valorisation de vêtements et accessoires.

Voici une liste de produits vestimentaires similaires trouvés sur Internet via Google Lens :

{$listJson}

Sur la base de ces résultats, réponds UNIQUEMENT avec un JSON valide (sans texte avant ni après) avec cette structure exacte :
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
}
