<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class OpenApiController extends Controller
{
    /**
     * Spécification OpenAPI 3 — importable (Postman, Stoplight, Insomnia, ReadMe, etc.).
     */
    public function yaml(): Response
    {
        $yaml = file_get_contents(resource_path('openapi/openapi.yaml')) ?: '';

        return response($yaml, 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
        ]);
    }
}
