<?php

use App\Http\Controllers\Api\V1\IndustryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/healthz', [HealthCheckController::class, 'basic']);
    Route::get('/healthz/deep', [HealthCheckController::class, 'deep']);

    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Industries endpoints
    Route::get('industries', [IndustryController::class, 'index']);
    Route::get('industries/autocomplete', [IndustryController::class, 'autocomplete']);
    Route::get('industries/{idOrSlug}', [IndustryController::class, 'show']);
});
