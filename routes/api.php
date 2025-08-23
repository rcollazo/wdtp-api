<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;

Route::prefix('v1')->group(function () {
    Route::get('/healthz', [HealthCheckController::class, 'basic']);
    Route::get('/healthz/deep', [HealthCheckController::class, 'deep']);
});