<?php

declare(strict_types=1);

use App\Http\Controllers\ApiVerifController;
use App\Http\Controllers\OpenApiController;
use App\Http\Controllers\SignedMediaController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');

Route::view('/docs', 'docs.redoc')->name('docs');

Route::get('/openapi.yaml', [OpenApiController::class, 'yaml'])->name('openapi.yaml');

Route::get('/docs/guide-ios', function () {
    $md = file_get_contents(base_path('docs/GUIDE_API_IOS.md')) ?: '';

    return response($md, 200, [
        'Content-Type' => 'text/markdown; charset=UTF-8',
    ]);
})->name('docs.guide.ios');

Route::get('/api-verif', ApiVerifController::class)->name('api.verif');

Route::get('/signed/media/{id}', [SignedMediaController::class, 'show'])->name('media.signed');
Route::get('/signed/media/{id}/frames/{index}', [SignedMediaController::class, 'frame'])->name('media.frame');
