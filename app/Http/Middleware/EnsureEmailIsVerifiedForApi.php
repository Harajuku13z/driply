<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque les routes API tant que l’utilisateur Sanctum n’a pas vérifié son e-mail.
 */
class EnsureEmailIsVerifiedForApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié.',
                'errors' => (object) [],
            ], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Adresse e-mail non vérifiée. Ouvre le lien reçu par mail ou renvoie l’e-mail depuis l’app.',
                'errors' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
