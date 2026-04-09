<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LensController;
use App\Http\Controllers\Api\MediaImportController;
use App\Http\Controllers\Api\OutfitController;
use App\Http\Controllers\Api\OutfitImageController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TagController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateMe']);
    Route::put('/me/password', [AuthController::class, 'updatePassword']);

    Route::get('/dashboard', DashboardController::class);

    Route::get('/outfits', [OutfitController::class, 'index']);
    Route::post('/outfits', [OutfitController::class, 'store']);
    Route::get('/outfits/{id}', [OutfitController::class, 'show']);
    Route::put('/outfits/{id}', [OutfitController::class, 'update']);
    Route::delete('/outfits/{id}', [OutfitController::class, 'destroy']);
    Route::get('/outfits/{id}/similar', [OutfitController::class, 'similar']);
    Route::post('/outfits/{id}/images', [OutfitImageController::class, 'store']);
    Route::delete('/outfits/{outfitId}/images/{imageId}', [OutfitImageController::class, 'destroy']);
    Route::put('/outfits/{outfitId}/images/{imageId}/primary', [OutfitImageController::class, 'setPrimary']);

    Route::middleware('throttle:search')->group(function (): void {
        Route::post('/search/images', [SearchController::class, 'images']);
        Route::post('/search/images/attach', [SearchController::class, 'attach']);
        Route::post('/search/lens', [LensController::class, 'analyze']);
    });

    Route::get('/search/lens/history', [LensController::class, 'history']);
    Route::get('/search/lens/{id}', [LensController::class, 'show']);
    Route::post('/search/lens/{id}/attach-outfit', [LensController::class, 'attachOutfit']);

    Route::post('/media/import', [MediaImportController::class, 'store']);
    Route::get('/media', [MediaImportController::class, 'index']);
    Route::get('/media/{id}', [MediaImportController::class, 'show']);
    Route::delete('/media/{id}', [MediaImportController::class, 'destroy']);
    Route::post('/media/{id}/extract-frames', [MediaImportController::class, 'extractFrames']);
    Route::post('/media/{id}/attach-outfit', [MediaImportController::class, 'attachOutfit']);

    Route::post('/scan/start', [ScanController::class, 'start']);
    Route::get('/scan/history', [ScanController::class, 'history']);
    Route::get('/scan/{scanId}', [ScanController::class, 'show']);
    Route::get('/scan/{scanId}/results', [ScanController::class, 'results']);
    Route::post('/scan/{scanId}/resolve', [ScanController::class, 'resolve']);

    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags', [TagController::class, 'store']);
    Route::delete('/tags/{id}', [TagController::class, 'destroy']);
    Route::post('/outfits/{id}/tags', [TagController::class, 'attachToOutfit']);
    Route::delete('/outfits/{id}/tags/{tagId}', [TagController::class, 'detachFromOutfit']);
});
