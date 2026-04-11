<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Exceptions\InspirationAnalysisException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Analyse une image via GPT-4o Vision pour detecter les vetements et accessoires.
 */
class ImageAnalysisService
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Tu es un expert en mode. Analyse cette image et retourne UNIQUEMENT un JSON valide sans texte ni backticks.
Structure attendue :
{
  "style": ["streetwear", "minimalist"],
  "colors": ["black", "white", "beige"],
  "gender": "femme | homme | unisexe",
  "items": [
    {
      "type": "blazer",
      "color": "black",
      "material": null,
      "brand": null,
      "confidence": 0.95
    }
  ]
}
Retourne uniquement le JSON, aucun autre texte.
PROMPT;

    /**
     * Analyse l'image encodee en base64 via GPT-4o Vision.
     *
     * @return array{style: list<string>, colors: list<string>, gender: string, items: list<array{type: string, color: string, material: ?string, brand: ?string, confidence: float}>}
     *
     * @throws InspirationAnalysisException
     */
    public function analyze(string $base64Image, string $mimeType = 'image/jpeg'): array
    {
        $apiKey  = (string) config('vision.openai.key');
        $model   = (string) config('vision.openai.model', 'gpt-4o');
        $timeout = (int) config('vision.openai.timeout', 30);

        if ($apiKey === '') {
            throw new InspirationAnalysisException('Cle API OpenAI manquante.');
        }

        try {
            $response = Http::timeout($timeout)
                ->retry(2, 500)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type'  => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => $model,
                    'temperature' => (float) config('vision.openai.temperature', 0),
                    'max_tokens'  => 1500,
                    'messages'    => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        [
                            'role'    => 'user',
                            'content' => [
                                [
                                    'type'      => 'image_url',
                                    'image_url' => [
                                        'url'    => "data:{$mimeType};base64,{$base64Image}",
                                        'detail' => 'high',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->failed()) {
                Log::error('Vision API error', ['status' => $response->status(), 'body' => $response->body()]);
                throw new InspirationAnalysisException('Impossible d\'analyser l\'image. Reessaie avec une photo plus nette.');
            }

            $content = (string) ($response->json('choices.0.message.content') ?? '');
            $content = trim($content);

            // Nettoyer les backticks markdown si presents
            if (str_starts_with($content, '```')) {
                $content = (string) preg_replace('/^```(?:json)?\s*/i', '', $content);
                $content = (string) preg_replace('/\s*```$/', '', $content);
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($content, true);

            if (! is_array($decoded) || empty($decoded['items'])) {
                Log::warning('Vision: JSON invalide ou vide', ['raw' => $content]);
                throw new InspirationAnalysisException('L\'image n\'a pas pu etre analysee. Essaie avec une photo de vetement ou accessoire.');
            }

            // Limiter le nombre d'items
            $maxItems = (int) config('vision.limits.max_items_per_scan', 3);
            $decoded['items'] = array_slice($decoded['items'], 0, $maxItems);

            return $decoded;
        } catch (InspirationAnalysisException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Vision: exception inattendue', ['error' => $e->getMessage()]);
            throw new InspirationAnalysisException('Erreur lors de l\'analyse de l\'image : ' . $e->getMessage());
        }
    }
}
