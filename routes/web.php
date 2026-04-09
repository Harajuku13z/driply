<?php

declare(strict_types=1);

use App\Http\Controllers\SignedMediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/signed/media/{id}', [SignedMediaController::class, 'show'])->name('media.signed');
Route::get('/signed/media/{id}/frames/{index}', [SignedMediaController::class, 'frame'])->name('media.frame');
