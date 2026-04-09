<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Requête courte Google Shopping à partir de la photo (GPT-4o vision).
 */
class LensImageVisionQueryService
{
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
