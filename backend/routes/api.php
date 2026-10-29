<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatchController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\SpeciesController;
use App\Http\Controllers\AdvisoryZoneController;
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
    Route::post('/species', [SpeciesController::class, 'store']);
    Route::put('/species/{id}', [SpeciesController::class, 'update']);

    // Catches (GPS is masked for unauthorized users)
    Route::get('/catches', [CatchController::class, 'index']);
    Route::post('/catches', [CatchController::class, 'store']);
    Route::get('/catches/{id}', [CatchController::class, 'show']);

    // AI Vision Fish Identification & Model Assistance
    Route::post('/ai/identify-fish', [\App\Http\Controllers\AiIdentificationController::class, 'identify']);

    // Meta Model API Health Check & Probe (Admin & Reviewer Only)
    Route::middleware(RequireAdminOrReviewer::class)->group(function () {
        Route::get('/ai/meta-health', [\App\Http\Controllers\AiIdentificationController::class, 'metaHealth']);
        Route::post('/ai/meta-health', [\App\Http\Controllers\AiIdentificationController::class, 'metaHealth']);
    });

    // Trips & Navigation Routes
    Route::get('/trips', [TripController::class, 'index']);
    Route::post('/trips', [TripController::class, 'store']);
    Route::patch('/trips/{id}', [TripController::class, 'update']);
    Route::get('/trips/{id}/route', [TripController::class, 'route']);
    Route::post('/trips/{id}/route-points', [TripController::class, 'appendRoutePoints']);

    // Fish Poisoning Advisory Zones & Point-in-Polygon Check
    Route::get('/advisory-zones', [AdvisoryZoneController::class, 'index']);
    Route::get('/advisory-zones/{id}', [AdvisoryZoneController::class, 'show']);
    Route::post('/advisory-zones/check-point', [AdvisoryZoneController::class, 'checkPoint']);

    // Editor Endpoints (Admin & Reviewer)
    Route::post('/advisory-zones', [AdvisoryZoneController::class, 'store']);
    Route::put('/advisory-zones/{id}', [AdvisoryZoneController::class, 'update']);
    Route::get('/advisory-zones/{id}/audit-logs', [AdvisoryZoneController::class, 'auditLogs']);

    // Publisher Endpoints (Admin Only)
    Route::post('/advisory-zones/{id}/publish', [AdvisoryZoneController::class, 'publish']);
    Route::post('/advisory-zones/{id}/unpublish', [AdvisoryZoneController::class, 'unpublish']);
    Route::delete('/advisory-zones/{id}', [AdvisoryZoneController::class, 'destroy']);

    // Admin & Reviewer Restricted Endpoints
    Route::middleware(RequireAdminOrReviewer::class)->prefix('admin')->group(function () {
        Route::get('/stats', [StatsController::class, 'index']);
        Route::post('/catches/{id}/verify', [VerificationController::class, 'verify']);
    });
});

