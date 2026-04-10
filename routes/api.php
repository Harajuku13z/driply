<?php

declare(strict_types=1);

use App\Http\Controllers\Api\LegalController;
use App\Http\Controllers\Api\V1\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\DashboardController as V1DashboardController;
use App\Http\Controllers\Api\V1\GroupeController as V1GroupeController;
use App\Http\Controllers\Api\V1\GroupeItemController as V1GroupeItemController;
use App\Http\Controllers\Api\V1\InspirationController as V1InspirationController;
use App\Http\Controllers\Api\V1\ScanController as V1ScanController;
use App\Http\Controllers\ApiVerifController;
use Illuminate\Support\Facades\Route;

Route::get('/verif', ApiVerifController::class);

Route::get('/legal', [LegalController::class, 'show']);

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [V1AuthController::class, 'register']);
    Route::post('/auth/login', [V1AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [V1AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [V1AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [V1AuthController::class, 'logout']);
        Route::get('/auth/me', [V1AuthController::class, 'me']);
        Route::post('/auth/email/resend', [V1AuthController::class, 'resendVerificationEmail'])
            ->middleware('throttle:6,1');

        Route::middleware('verified.api')->group(function (): void {
            Route::put('/auth/me', [V1AuthController::class, 'updateMe']);
            Route::put('/auth/me/password', [V1AuthController::class, 'updatePassword']);

            Route::get('/dashboard', V1DashboardController::class);

            Route::get('/groupes', [V1GroupeController::class, 'index']);
            Route::post('/groupes', [V1GroupeController::class, 'store']);
            Route::put('/groupes/reorder', [V1GroupeController::class, 'reorder']);
            Route::get('/groupes/{id}', [V1GroupeController::class, 'show']);
            Route::put('/groupes/{id}', [V1GroupeController::class, 'update']);
            Route::delete('/groupes/{id}', [V1GroupeController::class, 'destroy']);
            Route::put('/groupes/{id}/cover', [V1GroupeController::class, 'updateCover']);

            Route::put('/groupes/{groupeId}/items/reorder', [V1GroupeItemController::class, 'reorder']);
            Route::post('/groupes/{groupeId}/items', [V1GroupeItemController::class, 'store']);
            Route::put('/groupes/{groupeId}/items/{inspirationId}', [V1GroupeItemController::class, 'updateItemNote']);
            Route::delete('/groupes/{groupeId}/items/{inspirationId}', [V1GroupeItemController::class, 'destroy']);

            Route::get('/inspirations', [V1InspirationController::class, 'index']);
            Route::post('/inspirations', [V1InspirationController::class, 'store']);
            Route::post('/inspirations/scan', [V1ScanController::class, 'store'])
                ->middleware('throttle:scan-import');
            Route::post('/inspirations/import', [V1InspirationController::class, 'import'])
                ->middleware('throttle:scan-import');
            Route::get('/inspirations/{id}', [V1InspirationController::class, 'show']);
            Route::put('/inspirations/{id}', [V1InspirationController::class, 'update']);
            Route::delete('/inspirations/{id}', [V1InspirationController::class, 'destroy']);
            Route::post('/inspirations/{id}/favorite', [V1InspirationController::class, 'toggleFavorite']);

            Route::post('/scan', [V1ScanController::class, 'store'])
                ->middleware('throttle:scan-import');
        });
    });
});

Route::get('/email/verify/{id}/{hash}', [V1AuthController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');
