<?php

namespace App\Http\Controllers\Api;

use App\Services\GrandStreamProvisioningService;
use App\Services\TR069Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Phone Management Controller
 * 
 * Provides unified interface for managing VoIP phones (GXP1625, GXP1630)
 * Supports web management, API, webhooks, and TR-069
 */
class PhoneController extends Controller
{
    protected $grandstreamService;
    protected $tr069Service;

    public function __construct(
        GrandStreamProvisioningService $grandstreamService,
        TR069Service $tr069Service
    ) {
        $this->grandstreamService = $grandstreamService;
        $this->tr069Service = $tr069Service;
    }

    /**
     * List all phones (from Asterisk registrations)
     */
    public function index(Request $request)
    {
        $phones = $this->grandstreamService->discoverPhones();
        
        return response()->json([
            'success' => true,
            'phones' => $phones['phones'] ?? [],
            'total' => count($phones['phones'] ?? []),
        ]);
    }

    /**
     * Get detailed phone status
     */
    public function show(Request $request, $identifier)
    {
        // Identifier can be IP, MAC, or extension
        $ip = $this->resolvePhoneIP($identifier);
        
        if (!$ip) {
            return response()->json([
                'success' => false,
                'error' => 'Phone not found',
            ], 404);
        }

        $credentials = $request->input('credentials', []);
        $status = $this->grandstreamService->getPhoneStatus($ip, $credentials);

        return response()->json([
            'success' => true,
            'phone' => $status,
        ]);
    }

    /**
     * Control phone (reboot, reset, etc.)
     */
    public function control(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'action' => 'required|in:reboot,factory_reset,get_config,set_config,get_status',
            'credentials' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        $ip = $request->input('ip');
        $action = $request->input('action');
        $credentials = $request->input('credentials', []);

        $result = match($action) {
            'reboot' => $this->grandstreamService->rebootPhone($ip, $credentials),
            'factory_reset' => $this->grandstreamService->factoryResetPhone($ip, $credentials),
            'get_config' => $this->grandstreamService->getPhoneConfig($ip, $credentials),
            'set_config' => $this->grandstreamService->setPhoneConfig(
                $ip,
                $request->input('config', []),
                $credentials
            ),
            'get_status' => $this->grandstreamService->getPhoneStatus($ip, $credentials),
            default => ['success' => false, 'error' => 'Unknown action'],
        };

        return response()->json($result);
    }

    /**
     * Provision extension to phone
     */
    public function provision(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'extension_id' => 'required|integer|exists:extensions,id',
            'account_number' => 'nullable|integer|min:1|max:6',
            'credentials' => 'nullable|array',
        ]);

        $extension = \App\Models\Extension::findOrFail($request->extension_id);
        
        $result = $this->grandstreamService->provisionExtensionToPhone(
            $request->input('ip'),
            $extension->toArray(),
            $request->input('account_number', 1),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Manage phone via TR-069
     */
    public function tr069Manage(Request $request)
    {
        $request->validate([
            'serial_number' => 'required|string',
            'action' => 'required|in:get_params,set_params,reboot,factory_reset,configure_sip',
            'parameters' => 'nullable|array',
            'sip_config' => 'nullable|array',
        ]);

        $serialNumber = $request->input('serial_number');
        $action = $request->input('action');

        try {
            $result = match($action) {
                'get_params' => $this->tr069Service->getParameterValues(
                    $serialNumber,
                    $request->input('parameters', [])
                ),
                'set_params' => $this->tr069Service->setParameterValues(
                    $serialNumber,
                    $request->input('parameters', [])
                ),
                'reboot' => $this->tr069Service->reboot($serialNumber),
                'factory_reset' => $this->tr069Service->factoryReset($serialNumber),
                'configure_sip' => $this->tr069Service->configureSipAccount(
                    $serialNumber,
                    $request->input('account_number', 1),
                    $request->input('sip_config', [])
                ),
                default => ['success' => false, 'error' => 'Unknown action'],
            };

            return response()->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get TR-069 managed devices
     */
    public function tr069Devices()
    {
        $devices = $this->tr069Service->getAllDevices();

        return response()->json([
            'success' => true,
            'devices' => $devices,
            'total' => count($devices),
        ]);
    }

    /**
     * Webhook endpoint for phone events
     */
    public function webhook(Request $request)
    {
        $event = $request->input('event');
        $data = $request->input('data', []);

        Log::info("Phone webhook received", [
            'event' => $event,
            'data' => $data,
        ]);

        // Process webhook based on event type
        switch ($event) {
            case 'registration':
                // Handle phone registration event
                break;
            case 'call_start':
                // Handle call start event
                break;
            case 'call_end':
                // Handle call end event
                break;
            case 'config_change':
                // Handle configuration change event
                break;
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed',
        ]);
    }

    /**
     * Resolve phone IP from identifier (IP, MAC, or extension)
     */
    protected function resolvePhoneIP($identifier)
    {
        // If it's already an IP
        if (filter_var($identifier, FILTER_VALIDATE_IP)) {
            return $identifier;
        }

        // Try to find phone by extension or MAC
        $phones = $this->grandstreamService->discoverPhones();
        
        foreach ($phones['phones'] ?? [] as $phone) {
            if ($phone['extension'] === $identifier) {
                return $phone['ip'];
            }
        }

        return null;
    }
}
