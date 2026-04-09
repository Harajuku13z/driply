<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureEmailIsVerifiedForApi;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'verified.api' => EnsureEmailIsVerifiedForApi::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            if ($request->is('api/email/verify/*') && ! $request->wantsJson()) {
                return false;
            }

            return $request->is('api/*');
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Validation failed',
                    'errors' => $e->errors(),
                ], $e->status);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'errors' => (object) [],
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->is('api/email/verify/*') && ! $request->wantsJson()) {
                return response()->view('auth.verify-email-failed', [
                    'reason' => 'signature',
                ], 403);
            }

            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Lien invalide ou expiré.',
                    'errors' => (object) [],
                ], 403);
            }

            return null;
        });
    })
    ->create();
