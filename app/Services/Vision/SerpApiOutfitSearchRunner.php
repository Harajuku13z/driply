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
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());
            $combined = $stderr !== '' ? $stderr : $stdout;
            Log::error('SerpApiOutfitSearchRunner: echec process', [
                'exit' => $process->getExitCode(),
                'node' => $node,
                'script' => $script,
                'stderr' => $stderr !== '' ? $stderr : null,
                'stdout_head' => $stdout !== '' ? substr($stdout, 0, 2000) : null,
            ]);

            $userMessage = self::friendlyMessageFromCliFailure($combined, $process->getExitCode());
            if (config('app.debug')) {
                $hint = $combined !== '' ? substr($combined, 0, 280) : ('exit '.$process->getExitCode());
                $userMessage .= ' ['.$hint.']';
            }

            throw new InspirationAnalysisException($userMessage);
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

    /**
     * Interprète stderr du pont Node (souvent une ligne JSON) pour un message exploitable côté app.
     */
    private static function friendlyMessageFromCliFailure(string $combined, ?int $exitCode): string
    {
        $generic = 'Analyse SerpApi impossible pour le moment. Réessaie plus tard.';

        if ($combined === '') {
            if ($exitCode === 127 || $exitCode === 126) {
                return 'Node.js n’est pas disponible sur le serveur (commande « node » introuvable ou non exécutable). Définis NODE_BINARY dans .env ou installe Node.';
            }

            return $generic;
        }

        $decoded = json_decode($combined, true);
        if (is_array($decoded)) {
            $code = isset($decoded['error']) ? (string) $decoded['error'] : '';
            $msg = isset($decoded['message']) ? (string) $decoded['message'] : '';
            if ($code === 'missing_serpApiApiKey' || str_contains(strtolower($msg), 'api key')) {
                return 'Clé SerpApi manquante ou refusée. Vérifie SERPAPI_KEY dans .env.';
            }
            if ($code === 'pipeline_failed' && $msg !== '') {
                return self::friendlyMessageFromPipelineMessage($msg);
            }
            if ($msg !== '') {
                return self::friendlyMessageFromPipelineMessage($msg);
            }
        }

        return self::friendlyMessageFromPipelineMessage($combined);
    }

    private static function friendlyMessageFromPipelineMessage(string $raw): string
    {
        $generic = 'Analyse SerpApi impossible pour le moment. Réessaie plus tard.';
        $m = $raw;

        if (str_contains($m, 'Cannot find package') || str_contains($m, 'MODULE_NOT_FOUND') || str_contains($m, "Cannot find module")) {
            return 'Dépendances Node manquantes sur le serveur. Sur la machine qui héberge l’API, exécute « npm install » à la racine Driply-api, puis redémarre PHP.';
        }

        if (preg_match('/SerpApi HTTP\s*401|Invalid API key|invalid api key/i', $m) === 1) {
            return 'Clé SerpApi invalide ou désactivée. Vérifie SERPAPI_KEY sur https://serpapi.com/manage-api-key';
        }

        if (preg_match('/SerpApi HTTP\s*429|429|quota|run out of searches|too many requests/i', $m) === 1) {
            return 'Quota SerpApi dépassé ou limite de débit atteinte. Réessaie plus tard ou augmente ton forfait SerpApi.';
        }

        if (preg_match('/HTTP\s*403|forbidden/i', $m) === 1 && str_contains(strtolower($m), 'serpapi')) {
            return 'SerpApi a refusé la requête (403). Vérifie le compte SerpApi et les moteurs autorisés.';
        }

        if (str_contains($m, 'The network connection was lost')
            || str_contains($m, 'fetch failed')
            || str_contains($m, 'ECONNREFUSED')
            || str_contains($m, 'ENOTFOUND')
            || str_contains($m, 'ETIMEDOUT')) {
            return 'Le serveur n’a pas pu joindre SerpApi (réseau). Vérifie la connexion sortante du serveur.';
        }

        if (str_contains($m, 'AbortError') || str_contains($m, 'aborted') || preg_match('/timeout|timed out/i', $m) === 1) {
            return 'L’analyse a expiré (SerpApi ou réseau trop lent). Réessaie ou augmente SERPAPI_PROCESS_TIMEOUT.';
        }

        if (str_contains($m, 'resolvePublicUrl') || str_contains($m, 'URL publique')) {
            return 'L’image doit être accessible publiquement pour Google Lens (URL signée ou disque public). Vérifie APP_URL et le lien /storage ou driply-public.';
        }

        $trim = trim($m);
        if ($trim !== '' && strlen($trim) <= 200 && preg_match('/^[\p{L}\p{N}\s\-–—.,:;!?%€$\'"()\/&+]+$/u', $trim) === 1) {
            return $trim;
        }

        return $generic;
    }
}
