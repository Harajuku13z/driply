<?php

declare(strict_types=1);

use App\Http\Controllers\ApiVerifController;
use App\Http\Controllers\ForgotPasswordWebController;
use App\Http\Controllers\Legacy\LegacyHostingerUploadController;
use App\Http\Controllers\Legacy\LegacySyncMediaController;
use App\Http\Controllers\OpenApiController;
use App\Http\Controllers\PasswordResetWebController;
use App\Http\Controllers\PublicDiskFileController;
use App\Http\Controllers\SignedMediaController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home');

Route::view('/politique-de-confidentialite', 'legal.privacy')->name('legal.privacy');

// Images Lens (et autres sous-dossiers autorisés) : fonctionne sans `php artisan storage:link`.
Route::get('/driply-public/{path}', [PublicDiskFileController::class, 'show'])
    ->where('path', 'lens/.+')
    ->name('driply.public.storage');

// Demande de lien (e-mail) — même effet que POST /api/forgot-password.
Route::get('/mot-de-passe-oublie', [ForgotPasswordWebController::class, 'show'])
    ->name('password.request');
Route::post('/mot-de-passe-oublie', [ForgotPasswordWebController::class, 'send'])
    ->name('password.email');

// Déclarer /reset-password/success avant /reset-password/{token} (évite que « success » soit pris pour un token).
Route::get('/reset-password/success', [PasswordResetWebController::class, 'success'])
    ->name('password.reset.success');

// Lien généré par Illuminate\Auth\Notifications\ResetPassword (route nommée obligatoire).
Route::get('/reset-password/{token}', [PasswordResetWebController::class, 'show'])
    ->name('password.reset');

Route::post('/reset-password', [PasswordResetWebController::class, 'submit'])
    ->name('password.web.submit');

Route::view('/docs', 'docs.redoc')->name('docs');

Route::get('/openapi.yaml', [OpenApiController::class, 'yaml'])->name('openapi.yaml');

Route::get('/docs/guide-ios', function () {
    $md = file_get_contents(base_path('docs/GUIDE_API_IOS.md')) ?: '';

    return response($md, 200, [
        'Content-Type' => 'text/markdown; charset=UTF-8',
    ]);
})->name('docs.guide.ios');

Route::get('/api-verif', ApiVerifController::class)->name('api.verif');

Route::post('/upload.php', [LegacyHostingerUploadController::class, 'store'])
    ->name('legacy.upload')
    ->withoutMiddleware([ValidateCsrfToken::class]);

Route::post('/api/sync_media.php', [LegacySyncMediaController::class, 'store'])
    ->name('legacy.sync_media')
    ->withoutMiddleware([ValidateCsrfToken::class]);

Route::get('/signed/media/{id}', [SignedMediaController::class, 'show'])->name('media.signed');
Route::get('/signed/media/{id}/frames/{index}', [SignedMediaController::class, 'frame'])->name('media.frame');
