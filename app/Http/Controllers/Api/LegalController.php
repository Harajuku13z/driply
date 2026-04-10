<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LegalController extends Controller
{
    use ApiResponses;

    /**
     * URLs légales publiques (sans authentification), pour les apps mobiles et intégrations.
     */
    public function show(): JsonResponse
    {
        $override = trim((string) config('driply.privacy_policy_url', ''));
        $privacyPolicyUrl = $override !== '' ? $override : route('legal.privacy');

        return $this->success([
            'privacy_policy_url' => $privacyPolicyUrl,
        ]);
    }
}
