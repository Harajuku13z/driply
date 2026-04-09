<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use App\Exceptions\LensIdentificationFailedException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * GPT-4o vision : lit l’image en base64 (vérité terrain) avant Lens / Shopping.
 */
class LensImageVisionQueryService
{
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /**
     * @return array<string, mixed>
     */
    public function extractStructuredItemFromBytes(string $bytes, string $mimeType): array
    {
        $mime = Str::lower(trim($mimeType));
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new LensIdentificationFailedException('Format d’image non pris en charge (JPEG, PNG ou WebP).');
        }

        if ($bytes === '') {
            throw new LensIdentificationFailedException('Image vide.');
        }

        $key = (string) config('driply.openai.key', '');
        if ($key === '') {
            throw new ExternalServiceException('OpenAI API key is not configured');
        }

        $model = (string) config('driply.openai.model', 'gpt-4o');
        $b64 = base64_encode($bytes);
        $dataUrl = 'data:'.$mime.';base64,'.$b64;

        $userText = <<<'TXT'
Regarde cette image avec une précision maximale.
Décris le vêtement ou accessoire visible.
Réponds UNIQUEMENT avec un JSON valide, sans texte avant ou après, sans markdown, sans backticks.

{
  "brand": "marque exacte si visible sur le vêtement (logo, étiquette), sinon null",
  "item_type": "type précis ex: jean droit, sneaker basse, veste bomber, robe midi...",
  "model": "modèle exact si identifiable ex: 501, Superstar, Air Force 1, sinon null",
  "color_primary": "couleur principale EXACTE ex: noir, blanc, bleu indigo, beige",
  "color_secondary": "couleur secondaire si présente, sinon null",
  "color_hex": "code hex approximatif de la couleur principale ex: #1a1a1a",
  "material": "matière visible ex: denim, cuir lisse, coton, sinon null",
  "gender": "homme | femme | unisexe",
  "cut_style": "coupe ou style ex: slim, oversize, crop, regular, bootcut",
  "distinctive_details": "détails uniques ex: délavé, troué aux genoux, broderie",
  "search_query_google_en": "requête Google Shopping EN ANGLAIS ultra précise incluant couleur et marque",
  "search_query_google_fr": "même requête EN FRANÇAIS",
  "confidence": "high | medium | low"
}
TXT;

        try {
            $response = Http::timeout(120)
                ->withToken($key)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => $dataUrl,
                                        'detail' => 'high',
                                    ],
                                ],
                                ['type' => 'text', 'text' => $userText],
                            ],
                        ],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 900,
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new ExternalServiceException('OpenAI vision request failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new ExternalServiceException('OpenAI vision unavailable: '.$e->getMessage(), 0, $e);
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || $content === '') {
            throw new LensIdentificationFailedException('Article non identifiable. Essaie avec une photo plus nette.');
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new LensIdentificationFailedException('Article non identifiable. Essaie avec une photo plus nette.');
        }

        $itemType = trim((string) ($decoded['item_type'] ?? ''));
        if ($itemType === '') {
            throw new LensIdentificationFailedException('Article non identifiable. Essaie avec une photo plus nette.');
        }

        Log::info('lens.gpt_vision_description', ['payload' => $decoded]);

        return $decoded;
    }

    public function preciseShoppingQuery(string $imageHttpsUrl, string $fallbackQuery): string
    {
        $key = (string) config('driply.openai.key', '');
        if ($key === '') {
            return Str::limit(trim($fallbackQuery), 220, '');
        }

        $model = (string) config('driply.openai.model', 'gpt-4o');
        $fallback = Str::limit(trim($fallbackQuery), 220, '');

        $userText = <<<'TXT'
Décris précisément cet article vestimentaire pour une recherche Google Shopping. Donne UNIQUEMENT une requête de recherche courte et précise en français incluant : marque (si visible), type d'article, couleur exacte, modèle si identifiable.
Exemple : "Levi's 501 jean droit noir homme"
Réponds avec UNIQUEMENT la requête, rien d'autre — pas de guillemets, pas de phrase d'introduction.
TXT;

        try {
            $response = Http::timeout(120)
                ->withToken($key)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => 120,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => $imageHttpsUrl,
                                        'detail' => 'high',
                                    ],
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $userText,
                                ],
                            ],
                        ],
                    ],
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new ExternalServiceException('OpenAI vision request failed: '.$e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new ExternalServiceException('OpenAI vision unavailable: '.$e->getMessage(), 0, $e);
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($content)) {
            return $fallback;
        }

        $line = Str::limit(trim(preg_replace('/\s+/u', ' ', $content) ?? ''), 220, '');
        $line = trim($line, " \t\n\r\0\x0B\"'«»");

        if ($line === '' || Str::length($line) < 4) {
            return $fallback;
        }

        return $line;
    }
}
