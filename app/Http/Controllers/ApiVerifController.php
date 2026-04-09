<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiVerifController extends Controller
{
    /**
     * Page de diagnostic : état des briques nécessaires à l’API Driply.
     */
    public function __invoke(Request $request): Response
    {
        $expected = config('driply.api_verif_token');
        if (is_string($expected) && $expected !== '') {
            if ($request->query('token') !== $expected) {
                abort(403, 'Accès refusé : paramètre token invalide ou manquant.');
            }
        }

        $checks = $this->runChecks();
        $summary = [
            'ok' => count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'ok')),
            'warn' => count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warn')),
            'fail' => count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'fail')),
        ];

        if ($request->query('format') === 'json' || $request->wantsJson()) {
            return response()->json([
                'success' => $summary['fail'] === 0,
                'generated_at' => now()->toIso8601String(),
                'summary' => $summary,
                'checks' => $checks,
            ]);
        }

        return response()->view('api-verif', [
            'checks' => $checks,
            'summary' => $summary,
            'appVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
            'protected' => is_string($expected) && $expected !== '',
        ]);
    }

    /**
     * @return list<array{id: string, label: string, status: string, detail: string}>
     */
    private function runChecks(): array
    {
        $out = [];

        $out[] = $this->check('php', 'PHP ≥ 8.2', version_compare(PHP_VERSION, '8.2.0', '>=')
            ? 'ok'
            : 'fail', PHP_VERSION);

        $key = (string) config('app.key', '');
        $out[] = $this->check('app_key', 'APP_KEY définie', $key !== ''
            ? (str_contains($key, 'base64:') && strlen($key) > 20 ? 'ok' : 'warn')
            : 'fail', $key !== '' ? 'présente' : 'absente');

        $out[] = $this->check('env', 'APP_ENV', 'ok', (string) config('app.env'));

        try {
            DB::connection()->getPdo();
            $out[] = $this->check('database', 'Connexion base de données', 'ok', (string) config('database.default'));
        } catch (Throwable $e) {
            $out[] = $this->check('database', 'Connexion base de données', 'fail', $e->getMessage());
        }

        try {
            $hasUsers = Schema::hasTable('users');
            $out[] = $this->check('migrations_users', 'Table `users` (migrations)', $hasUsers ? 'ok' : 'fail',
                $hasUsers ? 'existe' : 'manquante — lancez php artisan migrate');
        } catch (Throwable $e) {
            $out[] = $this->check('migrations_users', 'Table `users`', 'fail', $e->getMessage());
        }

        try {
            $hasTokens = Schema::hasTable('personal_access_tokens');
            $out[] = $this->check('sanctum', 'Table Sanctum (`personal_access_tokens`)', $hasTokens ? 'ok' : 'fail',
                $hasTokens ? 'ok' : 'manquante');
        } catch (Throwable $e) {
            $out[] = $this->check('sanctum', 'Sanctum', 'fail', $e->getMessage());
        }

        try {
            $k = 'driply_verif_'.str_replace('.', '', (string) microtime(true));
            Cache::put($k, '1', 60);
            $ok = Cache::pull($k) === '1';
            $out[] = $this->check('cache', 'Cache ('.config('cache.default').')', $ok ? 'ok' : 'fail',
                $ok ? 'lecture/écriture OK' : 'échec');
        } catch (Throwable $e) {
            $out[] = $this->check('cache', 'Cache', 'fail', $e->getMessage());
        }

        $publicPath = storage_path('app/public');
        $pubWritable = File::isDirectory($publicPath) && File::isWritable($publicPath);
        $out[] = $this->check('storage_public', 'Stockage `storage/app/public` inscriptible', $pubWritable ? 'ok' : 'warn', $publicPath);

        $mediaPath = storage_path('app/media');
        if (! File::isDirectory($mediaPath)) {
            @mkdir($mediaPath, 0755, true);
        }
        $mediaWritable = File::isDirectory($mediaPath) && File::isWritable($mediaPath);
        $out[] = $this->check('storage_media', 'Stockage médias (`storage/app/media`)', $mediaWritable ? 'ok' : 'warn', $mediaPath);

        try {
            Storage::disk('public')->put('driply-verif.txt', '1');
            $r = Storage::disk('public')->get('driply-verif.txt') === '1';
            Storage::disk('public')->delete('driply-verif.txt');
            $out[] = $this->check('flysystem_public', 'Disque `public` (Flysystem)', $r ? 'ok' : 'fail', $r ? 'OK' : 'échec');
        } catch (Throwable $e) {
            $out[] = $this->check('flysystem_public', 'Disque `public`', 'fail', $e->getMessage());
        }

        $mailDriver = (string) config('mail.default', 'log');
        $mailHost = (string) config('mail.mailers.smtp.host', '');
        $mailUser = (string) config('mail.mailers.smtp.username', '');
        $isProd = config('app.env') === 'production';

        if ($mailDriver === 'log') {
            $out[] = $this->check(
                'mail_driver',
                'Envoi d\'e-mails (MAIL_MAILER)',
                $isProd ? 'fail' : 'warn',
                'MAIL_MAILER=log : aucun mail réel (vérification compte, etc.) — seulement écrit dans les logs. En production : MAIL_MAILER=smtp + identifiants Hostinger.'
            );
        } else {
            $smtpOk = $mailHost !== '' && $mailUser !== '';
            $out[] = $this->check(
                'mail_driver',
                'Envoi d\'e-mails ('.$mailDriver.')',
                $smtpOk ? 'ok' : 'warn',
                $smtpOk
                    ? $mailHost.' (port '.(string) config('mail.mailers.smtp.port').')'
                    : 'MAIL_HOST ou MAIL_USERNAME manquant pour SMTP'
            );
        }

        $out[] = $this->check('queue', 'File d\'attente', 'ok', (string) config('queue.default'));

        $serp = (string) config('driply.serpapi.key', '');
        $out[] = $this->check('serpapi', 'Clé SerpAPI (recherche images / Lens)', $serp !== '' ? 'ok' : 'warn',
            $serp !== '' ? 'renseignée' : 'SERPAPI_KEY vide — endpoints search limités');

        $openai = (string) config('driply.openai.key', '');
        $out[] = $this->check('openai', 'Clé OpenAI (analyse prix)', $openai !== '' ? 'ok' : 'warn',
            $openai !== '' ? 'renseignée' : 'OPENAI_API_KEY vide — Lens sans estimation GPT');

        $fastUrl = (string) config('driply.fastserver.url', '');
        $fastKey = (string) config('driply.fastserver.key', '');
        $fastHost = Str::lower((string) parse_url(rtrim($fastUrl, '/'), PHP_URL_HOST));
        $isFastSaver = $fastHost !== '' && str_contains($fastHost, 'fastsaverapi.com');
        if ($fastUrl === '') {
            $out[] = $this->check('fastserver', 'Fast Server / FastSaverAPI (import réseaux)', 'warn', 'FASTSERVER_URL vide');
        } elseif ($isFastSaver && $fastKey === '') {
            $out[] = $this->check('fastserver', 'Fast Server / FastSaverAPI (import réseaux)', 'warn', 'FastSaverAPI : FASTSERVER_KEY requis');
        } else {
            $out[] = $this->check('fastserver', 'Fast Server / FastSaverAPI (import réseaux)', 'ok',
                $isFastSaver ? 'FastSaverAPI (URL + token)' : 'URL renseignée');
        }

        return $out;
    }

    /**
     * @return array{id: string, label: string, status: string, detail: string}
     */
    private function check(string $id, string $label, string $status, string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
