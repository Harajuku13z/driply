<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Exceptions\InspirationAnalysisException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Exécute le package Node {@see https://github.com/... serpapi-outfit-search} via CLI (Lens + Shopping SerpApi).
 */
final class SerpApiOutfitSearchRunner
{
    /**
     * @return array<string, mixed> Décodage JSON identique à FinalSearchResponse TypeScript
     *
     * @throws InspirationAnalysisException
     */
    public function run(string $publicImageUrl): array
    {
        $key = (string) config('vision.serpapi.key', '');
        if ($key === '') {
            throw new InspirationAnalysisException('Configuration SerpApi manquante (SERPAPI_KEY).');
        }

        $node = (string) config('vision.serpapi.node_binary', 'node');
        $script = (string) config('vision.serpapi.node_script', base_path('bin/serpapi-outfit-search.mjs'));

        if (! is_readable($script)) {
            throw new InspirationAnalysisException('Script SerpApi outfit introuvable. Verifiez npm install et bin/serpapi-outfit-search.mjs.');
        }

        $payload = json_encode([
            'imageUrl' => $publicImageUrl,
            'serpApiApiKey' => $key,
            'language' => config('vision.serpapi.language', 'fr'),
            'country' => config('vision.serpapi.country', 'fr'),
            'googleDomain' => config('vision.serpapi.google_domain', 'google.com'),
            'lensType' => config('vision.serpapi.lens_type', 'visual_matches'),
            'timeoutMs' => (int) config('vision.serpapi.timeout', 25) * 1000,
            'maxRetries' => (int) config('vision.serpapi.retries', 2),
            'debug' => (bool) config('vision.debug_mode', false),
            'useMocks' => (bool) config('vision.serpapi.use_mocks', false),
            'maxSerpApiCallsPerSearch' => (int) config('vision.serpapi.max_calls_per_search', 28),
            'targetTopProductsPerItem' => (int) config('vision.limits.max_products_per_item', 10),
        ], JSON_THROW_ON_ERROR);

        $timeout = (float) config('vision.serpapi.process_timeout_seconds', 120);

        $process = new Process([$node, $script], base_path(), null, $payload, $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $err = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            Log::error('SerpApiOutfitSearchRunner: echec process', [
                'exit' => $process->getExitCode(),
                'stderr' => $err,
            ]);

            throw new InspirationAnalysisException('Analyse SerpApi impossible pour le moment. Reessaie plus tard.');
        }

        $out = trim($process->getOutput());
        if ($out === '') {
            throw new InspirationAnalysisException('Reponse SerpApi vide.');
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::error('SerpApiOutfitSearchRunner: JSON invalide', ['snippet' => substr($out, 0, 500)]);

            throw new InspirationAnalysisException('Reponse d\'analyse invalide.');
        }

        return $decoded;
    }
}
