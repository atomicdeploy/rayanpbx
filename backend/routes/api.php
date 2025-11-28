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
use App\Http\Controllers\Api\GrandStreamWebhookController;
use App\Http\Controllers\Api\PhoneController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\PjsipConfigController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\SipTestController;
use App\Http\Controllers\Api\ExtensionSyncController;
use App\Http\Controllers\Api\ResetController;
use App\Http\Controllers\Api\DirectCallController;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);
Route::get('/auth/pam-status', [AuthController::class, 'pamStatus']);

// GrandStream Action URL Webhooks (restricted to registered VoIP phone IPs)
Route::prefix('grandstream/webhook')->middleware(['voip.whitelist', 'throttle:100,1'])->group(function () {
    Route::match(['get', 'post'], '/setup-completed', [GrandStreamWebhookController::class, 'setupCompleted']);
    Route::match(['get', 'post'], '/registered', [GrandStreamWebhookController::class, 'registered']);
    Route::match(['get', 'post'], '/unregistered', [GrandStreamWebhookController::class, 'unregistered']);
    Route::match(['get', 'post'], '/register-failed', [GrandStreamWebhookController::class, 'registerFailed']);
    Route::match(['get', 'post'], '/off-hook', [GrandStreamWebhookController::class, 'offHook']);
    Route::match(['get', 'post'], '/on-hook', [GrandStreamWebhookController::class, 'onHook']);
    Route::match(['get', 'post'], '/incoming-call', [GrandStreamWebhookController::class, 'incomingCall']);
    Route::match(['get', 'post'], '/outgoing-call', [GrandStreamWebhookController::class, 'outgoingCall']);
    Route::match(['get', 'post'], '/missed-call', [GrandStreamWebhookController::class, 'missedCall']);
    Route::match(['get', 'post'], '/answered-call', [GrandStreamWebhookController::class, 'answeredCall']);
    Route::match(['get', 'post'], '/rejected-call', [GrandStreamWebhookController::class, 'rejectedCall']);
    Route::match(['get', 'post'], '/forwarded-call', [GrandStreamWebhookController::class, 'forwardedCall']);
    Route::match(['get', 'post'], '/established-call', [GrandStreamWebhookController::class, 'establishedCall']);
    Route::match(['get', 'post'], '/terminated-call', [GrandStreamWebhookController::class, 'terminatedCall']);
    Route::match(['get', 'post'], '/idle-to-busy', [GrandStreamWebhookController::class, 'idleToBusy']);
    Route::match(['get', 'post'], '/busy-to-idle', [GrandStreamWebhookController::class, 'busyToIdle']);
    Route::match(['get', 'post'], '/open-dnd', [GrandStreamWebhookController::class, 'openDnd']);
    Route::match(['get', 'post'], '/close-dnd', [GrandStreamWebhookController::class, 'closeDnd']);
    Route::match(['get', 'post'], '/open-forward', [GrandStreamWebhookController::class, 'openForward']);
    Route::match(['get', 'post'], '/close-forward', [GrandStreamWebhookController::class, 'closeForward']);
    Route::match(['get', 'post'], '/open-unconditional-forward', [GrandStreamWebhookController::class, 'openUnconditionalForward']);
    Route::match(['get', 'post'], '/close-unconditional-forward', [GrandStreamWebhookController::class, 'closeUnconditionalForward']);
    Route::match(['get', 'post'], '/open-busy-forward', [GrandStreamWebhookController::class, 'openBusyForward']);
    Route::match(['get', 'post'], '/close-busy-forward', [GrandStreamWebhookController::class, 'closeBusyForward']);
    Route::match(['get', 'post'], '/open-no-answer-forward', [GrandStreamWebhookController::class, 'openNoAnswerForward']);
    Route::match(['get', 'post'], '/close-no-answer-forward', [GrandStreamWebhookController::class, 'closeNoAnswerForward']);
    Route::match(['get', 'post'], '/blind-transfer', [GrandStreamWebhookController::class, 'blindTransfer']);
    Route::match(['get', 'post'], '/attended-transfer', [GrandStreamWebhookController::class, 'attendedTransfer']);
    Route::match(['get', 'post'], '/transfer-finished', [GrandStreamWebhookController::class, 'transferFinished']);
    Route::match(['get', 'post'], '/transfer-failed', [GrandStreamWebhookController::class, 'transferFailed']);
    Route::match(['get', 'post'], '/hold-call', [GrandStreamWebhookController::class, 'holdCall']);
    Route::match(['get', 'post'], '/unhold-call', [GrandStreamWebhookController::class, 'unholdCall']);
    Route::match(['get', 'post'], '/mute-call', [GrandStreamWebhookController::class, 'muteCall']);
    Route::match(['get', 'post'], '/unmute-call', [GrandStreamWebhookController::class, 'unmuteCall']);
    Route::match(['get', 'post'], '/open-syslog', [GrandStreamWebhookController::class, 'openSyslog']);
    Route::match(['get', 'post'], '/close-syslog', [GrandStreamWebhookController::class, 'closeSyslog']);
    Route::match(['get', 'post'], '/ip-change', [GrandStreamWebhookController::class, 'ipChange']);
    Route::match(['get', 'post'], '/auto-provision-finish', [GrandStreamWebhookController::class, 'autoProvisionFinish']);
    
    // Generic event handler
    Route::match(['get', 'post'], '/{event}', function ($event) {
        // Convert kebab-case to snake_case
        $event_snake = str_replace('-', '_', $event);
        return app(\App\Http\Controllers\Api\GrandStreamWebhookController::class)->handleEvent($event_snake);
    });
});

// Get Action URL configuration (restricted to whitelisted VoIP phone IPs)
Route::get('/grandstream/action-urls', [GrandStreamWebhookController::class, 'getActionUrls'])
    ->middleware(['voip.whitelist', 'throttle:60,1']);

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::get('/auth/sessions', [AuthController::class, 'sessions']);
    Route::delete('/auth/sessions/{session}', [AuthController::class, 'revokeSession']);
    
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
    
    // Extension Sync
    Route::get('/extensions/sync/status', [ExtensionSyncController::class, 'status']);
    Route::post('/extensions/sync/db-to-asterisk', [ExtensionSyncController::class, 'syncDatabaseToAsterisk']);
    Route::post('/extensions/sync/asterisk-to-db', [ExtensionSyncController::class, 'syncAsteriskToDatabase']);
    Route::post('/extensions/sync/all-db-to-asterisk', [ExtensionSyncController::class, 'syncAllDatabaseToAsterisk']);
    Route::post('/extensions/sync/all-asterisk-to-db', [ExtensionSyncController::class, 'syncAllAsteriskToDatabase']);
    
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
    Route::get('/console/live', [ConsoleController::class, 'live']);
    Route::get('/console/errors', [ConsoleController::class, 'errors']);
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
    
    // GrandStream Action URL Management
    Route::post('/grandstream/action-urls/check', [GrandStreamController::class, 'checkActionUrls']);
    Route::post('/grandstream/action-urls/update', [GrandStreamController::class, 'updateActionUrls']);
    Route::post('/grandstream/provision-complete', [GrandStreamController::class, 'provisionComplete']);
    
    // GrandStream CTI/CSTA Operations
    Route::post('/grandstream/cti/status', [GrandStreamController::class, 'getCTIStatus']);
    Route::post('/grandstream/cti/line-status', [GrandStreamController::class, 'getLineStatus']);
    Route::post('/grandstream/cti/operation', [GrandStreamController::class, 'executeCTIOperation']);
    Route::post('/grandstream/cti/lcd-message', [GrandStreamController::class, 'displayLCDMessage']);
    Route::post('/grandstream/cti/screenshot', [GrandStreamController::class, 'takeScreenshot']);
    Route::post('/grandstream/cti/enable', [GrandStreamController::class, 'enableCTI']);
    Route::post('/grandstream/cti/disable', [GrandStreamController::class, 'disableCTI']);
    Route::post('/grandstream/cti/provision', [GrandStreamController::class, 'provisionCTIFeatures']);
    Route::post('/grandstream/cti/test', [GrandStreamController::class, 'testCTIFeatures']);
    Route::post('/grandstream/cti/trigger-provision', [GrandStreamController::class, 'triggerProvision']);
    Route::post('/grandstream/cti/trigger-upgrade', [GrandStreamController::class, 'triggerUpgrade']);
    
    // GrandStream SNMP Operations
    Route::post('/grandstream/snmp/enable', [GrandStreamController::class, 'enableSNMP']);
    Route::post('/grandstream/snmp/disable', [GrandStreamController::class, 'disableSNMP']);
    Route::post('/grandstream/snmp/status', [GrandStreamController::class, 'getSNMPStatus']);
    
    // Unified Phone Management API
    Route::get('/phones', [PhoneController::class, 'index']);
    Route::post('/phones', [PhoneController::class, 'store']); // Add new phone
    Route::get('/phones/lldp/neighbors', [PhoneController::class, 'lldpNeighbors']);
    Route::get('/phones/arp/neighbors', [PhoneController::class, 'arpNeighbors']);
    Route::get('/phones/discover', [PhoneController::class, 'discover']);
    Route::post('/phones/authenticate', [PhoneController::class, 'authenticate']); // Authenticate to phone
    Route::get('/phones/{identifier}', [PhoneController::class, 'show']);
    Route::put('/phones/{id}', [PhoneController::class, 'update']); // Update phone (including credentials)
    Route::delete('/phones/{id}', [PhoneController::class, 'destroy']); // Delete phone
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
    
    // System Reset (destructive - requires confirmation)
    Route::get('/system/reset/summary', [ResetController::class, 'summary']);
    Route::post('/system/reset', [ResetController::class, 'reset']);
    
    // Direct Call & Console Softphone
    Route::post('/calls/originate', [DirectCallController::class, 'originate']);
    Route::post('/calls/dial-from-console', [DirectCallController::class, 'dialFromConsole']);
    Route::post('/calls/console/answer', [DirectCallController::class, 'answerConsole']);
    Route::post('/calls/console/hangup', [DirectCallController::class, 'hangupConsole']);
    Route::get('/calls/console/status', [DirectCallController::class, 'getConsoleStatus']);
    Route::post('/calls/console/configure', [DirectCallController::class, 'configureConsole']);
    Route::get('/calls/console/dialplan', [DirectCallController::class, 'getConsoleDialplan']);
    Route::get('/calls/status/{callId}', [DirectCallController::class, 'getCallStatus']);
    Route::get('/calls/list', [DirectCallController::class, 'listCalls']);
    Route::post('/calls/hangup', [DirectCallController::class, 'hangup']);
    Route::post('/calls/dtmf', [DirectCallController::class, 'sendDTMF']);
    Route::post('/calls/test', [DirectCallController::class, 'testCall']);
    Route::post('/calls/phone', [DirectCallController::class, 'callPhone']);
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
