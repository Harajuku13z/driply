<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legacy;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibilité app iOS : POST multipart (champ "file") comme l’ancien upload.php Hostinger.
 * Réponse : { "ok": true, "url": "https://…" } pour SerpAPI / Google Lens.
 */
class LegacyHostingerUploadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if ($fail = $this->rejectUnlessLegacyKeyValid($request)) {
            return $fail;
        }

        $request->validate([
            'file' => ['required', 'file', 'image', 'max:12288'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $path = 'ios-uploads/'.Str::uuid()->toString().'.'.$ext;

        Storage::disk('public')->put($path, (string) file_get_contents($file->getRealPath()));

        $url = rtrim((string) config('app.url'), '/').Storage::disk('public')->url($path);

        return response()->json([
            'ok' => true,
            'url' => $url,
        ]);
    }

    private function rejectUnlessLegacyKeyValid(Request $request): ?JsonResponse
    {
        $expected = (string) config('driply.legacy_api_key', '');
        if ($expected === '') {
            return null;
        }

        $given = (string) $request->header('X-Driply-Key', '');
        if (! hash_equals($expected, $given)) {
            return response()->json([
                'ok' => false,
                'error' => 'Clé API invalide ou absente (X-Driply-Key).',
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
