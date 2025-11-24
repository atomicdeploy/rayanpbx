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
use App\Http\Controllers\Api\AsteriskStatusController;
use App\Http\Controllers\Api\ValidationController;
use App\Http\Controllers\Api\GrandStreamController;
use App\Http\Controllers\Api\PhoneController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\PjsipConfigController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\SipTestController;

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
    Route::get('/extensions/{id}/verify', [ExtensionController::class, 'verify'])->name('api.extensions.verify');
    Route::get('/extensions/{id}/diagnostics', [ExtensionController::class, 'diagnostics']);
    Route::get('/extensions/asterisk/endpoints', [ExtensionController::class, 'asteriskEndpoints'])->name('api.extensions.asterisk.endpoints');
    
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
    
    // Asterisk Real-time Status
    Route::post('/asterisk/endpoint/status', [AsteriskStatusController::class, 'getEndpointStatus']);
    Route::get('/asterisk/endpoints', [AsteriskStatusController::class, 'getAllEndpoints']);
    Route::post('/asterisk/channel/codec', [AsteriskStatusController::class, 'getChannelCodec']);
    Route::post('/asterisk/channel/rtp', [AsteriskStatusController::class, 'getRTPStats']);
    Route::post('/asterisk/trunk/status', [AsteriskStatusController::class, 'getTrunkStatus']);
    Route::get('/asterisk/status/complete', [AsteriskStatusController::class, 'getCompleteStatus']);
    Route::get('/asterisk/errors', [AsteriskStatusController::class, 'getErrors']);
    Route::get('/asterisk/transports', [AsteriskStatusController::class, 'getTransports']);
    Route::post('/asterisk/reload', [AsteriskStatusController::class, 'reloadPjsip']);
    Route::post('/asterisk/restart', [AsteriskStatusController::class, 'restartAsterisk']);
    
    // Configuration Validation & Testing
    Route::post('/validate/pjsip', [ValidationController::class, 'validatePjsip']);
    Route::post('/validate/dialplan', [ValidationController::class, 'validateDialplan']);
    Route::post('/validate/analyze', [ValidationController::class, 'analyzeConfig']);
    Route::get('/validate/trunk/{name}', [ValidationController::class, 'validateTrunk']);
    Route::get('/validate/extension/{extension}', [ValidationController::class, 'validateExtension']);
    Route::post('/validate/routing', [ValidationController::class, 'testRouting']);
    Route::get('/validate/hooks/registration', [ValidationController::class, 'getRegistrationHooks']);
    Route::get('/validate/hooks/grandstream', [ValidationController::class, 'getGrandstreamHooks']);
    
    // GrandStream Phone Provisioning
    Route::get('/grandstream/devices', [GrandStreamController::class, 'listDevices']);
    Route::post('/grandstream/scan', [GrandStreamController::class, 'scanNetwork']);
    Route::get('/grandstream/provision/{mac}', [GrandStreamController::class, 'getProvisioningConfig'])->name('grandstream.provision');
    Route::post('/grandstream/configure/{mac}', [GrandStreamController::class, 'configurePhone']);
    Route::get('/grandstream/status/{mac}', [GrandStreamController::class, 'getPhoneStatus']);
    Route::post('/grandstream/assign-extension', [GrandStreamController::class, 'assignExtension']);
    Route::get('/grandstream/models', [GrandStreamController::class, 'getSupportedModels']);
    Route::get('/grandstream/hooks', [GrandStreamController::class, 'getProvisioningHooks']);
    
    // GrandStream Phone Control
    Route::post('/grandstream/reboot', [GrandStreamController::class, 'rebootPhone']);
    Route::post('/grandstream/factory-reset', [GrandStreamController::class, 'factoryResetPhone']);
    Route::post('/grandstream/config/get', [GrandStreamController::class, 'getPhoneConfig']);
    Route::post('/grandstream/config/set', [GrandStreamController::class, 'setPhoneConfig']);
    Route::post('/grandstream/provision-direct', [GrandStreamController::class, 'provisionExtensionDirect']);
    
    // Unified Phone Management API
    Route::get('/phones', [PhoneController::class, 'index']);
    Route::get('/phones/{identifier}', [PhoneController::class, 'show']);
    Route::post('/phones/control', [PhoneController::class, 'control']);
    Route::post('/phones/provision', [PhoneController::class, 'provision']);
    Route::post('/phones/tr069/manage', [PhoneController::class, 'tr069Manage']);
    Route::get('/phones/tr069/devices', [PhoneController::class, 'tr069Devices']);
    Route::post('/phones/webhook', [PhoneController::class, 'webhook']);
    
    // AMI Event Monitoring
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/registrations', [EventController::class, 'registrations']);
    Route::get('/events/calls', [EventController::class, 'calls']);
    Route::get('/events/extension/{extension}', [EventController::class, 'extensionStatus']);
    Route::post('/events/clear', [EventController::class, 'clear']);
    
    // PJSIP Global Configuration
    Route::get('/pjsip/config/global', [PjsipConfigController::class, 'getGlobal']);
    Route::post('/pjsip/config/external-media', [PjsipConfigController::class, 'updateExternalMedia']);
    Route::post('/pjsip/config/transport', [PjsipConfigController::class, 'updateTransport']);
    
    // Configuration Management
    Route::get('/config', [ConfigController::class, 'index']);
    Route::get('/config/{key}', [ConfigController::class, 'show']);
    Route::post('/config', [ConfigController::class, 'store']);
    Route::put('/config/{key}', [ConfigController::class, 'update']);
    Route::delete('/config/{key}', [ConfigController::class, 'destroy']);
    Route::post('/config/reload', [ConfigController::class, 'reload']);
    
    // SIP Testing
    Route::get('/sip-test/tools', [SipTestController::class, 'checkTools']);
    Route::post('/sip-test/tools/install', [SipTestController::class, 'installTool']);
    Route::post('/sip-test/registration', [SipTestController::class, 'testRegistration']);
    Route::post('/sip-test/call', [SipTestController::class, 'testCall']);
    Route::post('/sip-test/full', [SipTestController::class, 'testFull']);
    Route::post('/sip-test/options', [SipTestController::class, 'testOptions']);
});

// Health check endpoint (public)
// Usage: curl -s http://localhost:8000/api/health | jq '.'
// Extract specific fields: curl -s http://localhost:8000/api/health | jq -r '.status, .services.database'
Route::get('/health', function () {
    try {
        // Check database connectivity
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $databaseStatus = 'connected';
    } catch (\Exception $e) {
        $databaseStatus = 'disconnected';
    }
    
    // Check Asterisk AMI connectivity
    try {
        $socket = fsockopen(
            config('rayanpbx.asterisk.ami_host', '127.0.0.1'),
            config('rayanpbx.asterisk.ami_port', 5038),
            $errno,
            $errstr,
            2
        );
        
        if ($socket) {
            fclose($socket);
            $asteriskStatus = 'running';
        } else {
            $asteriskStatus = 'stopped';
        }
    } catch (\Exception $e) {
        $asteriskStatus = 'unknown';
    } catch (\ErrorException $e) {
        // fsockopen can throw ErrorException on connection failure
        $asteriskStatus = 'stopped';
    }
    
    // Check CORS configuration
    $corsAllowedOrigins = config('cors.allowed_origins', []);
    $corsConfig = [
        'enabled' => !empty($corsAllowedOrigins),
        'allowed_origins' => $corsAllowedOrigins,
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
        'additional_origins' => env('CORS_ALLOWED_ORIGINS', ''),
    ];
    
    return response()->json([
        'status' => 'healthy',
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0',
        'services' => [
            'database' => $databaseStatus,
            'asterisk' => $asteriskStatus,
        ],
        'app' => [
            'name' => config('app.name', 'RayanPBX'),
            'env' => config('app.env'),
            'debug' => (bool) config('app.debug'),
        ],
        'cors' => $corsConfig,
    ]);
});
