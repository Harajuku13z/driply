<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibilité app iOS : POST JSON vers api/sync_media.php avec X-Driply-Key.
 * Enregistre un aperçu dans les logs (pas de schéma MySQL legacy).
 */
class LegacySyncMediaController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $expected = (string) config('driply.legacy_api_key', '');
        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'error' => 'DRIPLY_LEGACY_API_KEY doit être défini sur le serveur pour la synchronisation.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $given = (string) $request->header('X-Driply-Key', '');
        if (! hash_equals($expected, $given)) {
            return response()->json([
                'ok' => false,
                'error' => 'Clé API invalide ou absente (X-Driply-Key).',
            ], Response::HTTP_FORBIDDEN);
        }

        $payload = $request->all();
        Log::info('driply.legacy_sync_media', [
            'kind' => $payload['kind'] ?? null,
            'device_id' => $payload['device_id'] ?? null,
            'image_url' => $payload['image_url'] ?? null,
        ]);

        return response()->json(['ok' => true], Response::HTTP_OK);
    }
}
