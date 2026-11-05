<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MfaController;

Route::prefix('v1')->name('margin.auth.')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/mfa/challenge', [MfaController::class, 'challenge'])->middleware('throttle:5,1')->name('mfa.challenge');

    Route::middleware(['auth:web', EnsureAccountIsActive::class])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});
