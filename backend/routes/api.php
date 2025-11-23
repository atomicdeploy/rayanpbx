<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExtensionController;
use App\Http\Controllers\Api\TrunkController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\ConsoleController;
use App\Http\Controllers\Api\HelpController;
use App\Http\Controllers\Api\TrafficController;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);

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
    
    // Asterisk Console
    Route::post('/console/execute', [ConsoleController::class, 'execute']);
    Route::get('/console/output', [ConsoleController::class, 'output']);
    Route::get('/console/commands', [ConsoleController::class, 'commands']);
    Route::get('/console/version', [ConsoleController::class, 'version']);
    Route::get('/console/calls', [ConsoleController::class, 'calls']);
    Route::get('/console/channels', [ConsoleController::class, 'channels']);
    Route::get('/console/endpoints', [ConsoleController::class, 'endpoints']);
    Route::get('/console/registrations', [ConsoleController::class, 'registrations']);
    Route::post('/console/reload', [ConsoleController::class, 'reload']);
    Route::post('/console/hangup', [ConsoleController::class, 'hangup']);
    Route::post('/console/originate', [ConsoleController::class, 'originate']);
    Route::get('/console/dialplan', [ConsoleController::class, 'dialplan']);
    Route::get('/console/peers', [ConsoleController::class, 'peers']);
    Route::get('/console/session', [ConsoleController::class, 'session']);
    
    // AI Help & Explanations
    Route::post('/help/explain', [HelpController::class, 'explain']);
    Route::post('/help/error', [HelpController::class, 'explainError']);
    Route::post('/help/codec', [HelpController::class, 'explainCodec']);
    Route::post('/help/field', [HelpController::class, 'getFieldHelp']);
    Route::post('/help/batch', [HelpController::class, 'explainBatch']);
    
    // Traffic Analysis
    Route::post('/traffic/start', [TrafficController::class, 'start']);
    Route::post('/traffic/stop', [TrafficController::class, 'stop']);
    Route::get('/traffic/status', [TrafficController::class, 'status']);
    Route::get('/traffic/analyze', [TrafficController::class, 'analyze']);
    Route::post('/traffic/clear', [TrafficController::class, 'clear']);
});

// Health check endpoint (public)
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0',
    ]);
});
