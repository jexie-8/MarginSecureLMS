<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Middleware\BindSessionToClient;
use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\MfaController;

Route::prefix('v1')->name('margin.auth.')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/mfa/challenge', [MfaController::class, 'challenge'])->middleware('throttle:5,1')->name('mfa.challenge');

    // Mobile app (bearer tokens). The browser app keeps using the cookie routes above.
    Route::post('/mobile/login', [MobileAuthController::class, 'login'])->middleware('throttle:20,1')->name('mobile.login');
    Route::post('/mobile/mfa/challenge', [MobileAuthController::class, 'challenge'])->middleware('throttle:5,1')->name('mobile.mfa.challenge');

    Route::middleware(['auth:sanctum', EnsureAccountIsActive::class, BindSessionToClient::class, EnforceAbsoluteSessionLifetime::class, 'throttle:margin-api'])->group(function (): void {
        Route::post('/mobile/logout', [MobileAuthController::class, 'logout'])->name('mobile.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});
