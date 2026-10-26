<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatchController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\SpeciesController;
use App\Http\Middleware\RequireAdminOrReviewer;

// Public Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Kiribati Fisheries Assistant API',
        'time' => now()->toIso8601String(),
    ]);
});

// Authentication
Route::post('/auth/login', [AuthController::class, 'login']);

// Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Species Catalog
    Route::get('/species', [SpeciesController::class, 'index']);

    // Catches (GPS is masked for unauthorized users)
    Route::get('/catches', [CatchController::class, 'index']);
    Route::get('/catches/{id}', [CatchController::class, 'show']);

    // Trips & Navigation Routes
    Route::get('/trips', [TripController::class, 'index']);
    Route::get('/trips/{id}/route', [TripController::class, 'route']);

    // Admin & Reviewer Restricted Endpoints
    Route::middleware(RequireAdminOrReviewer::class)->prefix('admin')->group(function () {
        Route::get('/stats', [StatsController::class, 'index']);
        Route::post('/catches/{id}/verify', [VerificationController::class, 'verify']);
    });
});
