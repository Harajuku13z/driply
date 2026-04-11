<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy;

use App\Http\Controllers\Controller;
use App\Services\LinkPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aperçu page web (titre, image, prix) pour l’import depuis Safari / partage — même clé que sync_media.
 */
class LegacyLinkPreviewController extends Controller
{
    public function store(Request $request, LinkPreviewService $linkPreview): JsonResponse
    {
        $expected = (string) config('driply.legacy_api_key', '');
        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'error' => 'DRIPLY_LEGACY_API_KEY doit être défini sur le serveur.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $given = (string) $request->header('X-Driply-Key', '');
        if (! hash_equals($expected, $given)) {
            return response()->json([
                'ok' => false,
                'error' => 'Clé API invalide ou absente (X-Driply-Key).',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $result = $linkPreview->preview($validated['url']);

        return response()->json($result, Response::HTTP_OK);
    }
}
