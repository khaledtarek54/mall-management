<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Mobile (tenant-facing)
|--------------------------------------------------------------------------
| Sanctum token auth against the `tenants` provider. Web/admin endpoints
| are NOT exposed here; the Filament panels at /admin /portal /owner are
| separate session-based flows.
|
| Routing convention:
|   - Versioned under /api/v1
|   - Standard Laravel JSON envelope: { data: ..., message: ... }
|   - 401 unauthenticated, 422 validation, 403 forbidden, 429 throttled
*/

Route::prefix('v1')->group(function () {

    // ============ Public (unauthenticated) ============
    // Login: throttled to 5 attempts per minute per email+ip to slow down
    // credential-stuffing without blocking real users.
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('auth/login', LoginController::class)->name('api.v1.auth.login');
    });

    // ============ Authenticated (Sanctum tenant-api guard) ============
    Route::middleware('auth:tenant-api')->group(function () {
        Route::get('auth/me', MeController::class)->name('api.v1.auth.me');
        Route::post('auth/logout', \App\Http\Controllers\Api\V1\Auth\LogoutController::class)
            ->name('api.v1.auth.logout');
    });

});
