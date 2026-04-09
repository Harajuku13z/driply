<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

trait ApiResponses
{
    /**
     * @param  array<string, mixed>|object|null  $data
     * @param  array<string, mixed>  $meta
     */
    protected function success(mixed $data = null, string $message = '', array $meta = []): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => empty($meta) ? (object) [] : $meta,
        ];

        return response()->json($payload);
    }

    /**
     * @param  array<string, array<int, string>|string>  $errors
     */
    protected function error(string $message, int $status = 422, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => empty($errors) ? (object) [] : $errors,
        ], $status);
    }

    /**
     * @param  LengthAwarePaginator<mixed>  $paginator
     * @param  callable(mixed): mixed  $mapper
     * @param  array<string, mixed>  $extraMeta
     */
    protected function paginated(LengthAwarePaginator $paginator, callable $mapper, array $extraMeta = []): JsonResponse
    {
        $items = $paginator->getCollection()->map($mapper)->values()->all();

        $meta = array_merge($extraMeta, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);

        return $this->success($items, '', $meta);
    }

    /**
     * @param  array<string, mixed>|object|null  $data
     */
    protected function created(mixed $data, string $message = 'Created'): JsonResponse
    {
        return $this->success($data, $message)->setStatusCode(201);
    }
}
