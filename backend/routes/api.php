<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExtensionController;
use App\Http\Controllers\Api\TrunkController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\LogController;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);
    
    // Extensions
    Route::get('/extensions', [ExtensionController::class, 'index']);
    Route::post('/extensions', [ExtensionController::class, 'store']);
    Route::get('/extensions/{id}', [ExtensionController::class, 'show']);
    Route::put('/extensions/{id}', [ExtensionController::class, 'update']);
    Route::delete('/extensions/{id}', [ExtensionController::class, 'destroy']);
    Route::post('/extensions/{id}/toggle', [ExtensionController::class, 'toggle']);
    
    // Trunks
    Route::get('/trunks', [TrunkController::class, 'index']);
    Route::post('/trunks', [TrunkController::class, 'store']);
    Route::get('/trunks/{id}', [TrunkController::class, 'show']);
    Route::put('/trunks/{id}', [TrunkController::class, 'update']);
    Route::delete('/trunks/{id}', [TrunkController::class, 'destroy']);
    
    // Status & Monitoring
    Route::get('/status', [StatusController::class, 'index']);
    Route::get('/status/extensions', [StatusController::class, 'extensions']);
    Route::get('/status/trunks', [StatusController::class, 'trunks']);
    
    // Logs
    Route::get('/logs', [LogController::class, 'index']);
    Route::get('/logs/stream', [LogController::class, 'stream']);
});
