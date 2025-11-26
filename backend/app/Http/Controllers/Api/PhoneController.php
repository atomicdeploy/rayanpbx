<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VoipPhone;
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
     * List all phones (from database and Asterisk registrations)
     */
    public function index(Request $request)
    {
        // Get phones from database
        $dbPhones = VoipPhone::orderBy('last_seen', 'desc')->get();
        
        // Get phones from Asterisk registrations
        $discoveredPhones = $this->grandstreamService->discoverPhones();
        $sipPhones = $discoveredPhones['phones'] ?? [];
        
        // Merge database phones with discovered phones
        $phones = $dbPhones->map(function ($phone) {
            return [
                'id' => $phone->id,
                'ip' => $phone->ip,
                'mac' => $phone->mac,
                'extension' => $phone->extension,
                'name' => $phone->getDisplayName(),
                'vendor' => $phone->vendor,
                'model' => $phone->model,
                'firmware' => $phone->firmware,
                'status' => $phone->status,
                'discovery_type' => $phone->discovery_type,
                'user_agent' => $phone->user_agent,
                'cti_enabled' => $phone->cti_enabled,
                'snmp_enabled' => $phone->snmp_enabled,
                'last_seen' => $phone->last_seen?->toIso8601String(),
                'source' => 'database',
            ];
        })->toArray();
        
        // Add any SIP-registered phones not in database
        foreach ($sipPhones as $sipPhone) {
            $exists = collect($phones)->firstWhere('ip', $sipPhone['ip'] ?? null);
            if (!$exists && !empty($sipPhone['ip'])) {
                $phones[] = array_merge($sipPhone, [
                    'source' => 'asterisk',
                    'status' => 'registered',
                ]);
            }
        }
        
        return response()->json([
            'success' => true,
            'phones' => array_values($phones),
            'total' => count($phones),
        ]);
    }

    /**
     * Store a new phone
     */
    public function store(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip|unique:voip_phones,ip',
            'mac' => 'nullable|string|max:17',
            'extension' => 'nullable|string|max:32',
            'name' => 'nullable|string|max:100',
            'vendor' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:50',
            'credentials' => 'nullable|array',
            'discovery_type' => 'nullable|string|max:20',
        ]);

        $phone = VoipPhone::create([
            'ip' => $request->input('ip'),
            'mac' => $request->input('mac'),
            'extension' => $request->input('extension'),
            'name' => $request->input('name'),
            'vendor' => $request->input('vendor', 'grandstream'),
            'model' => $request->input('model'),
            'credentials' => $request->input('credentials'),
            'discovery_type' => $request->input('discovery_type', 'manual'),
            'status' => 'discovered',
            'last_seen' => now(),
        ]);

        return response()->json([
            'success' => true,
            'phone' => $phone,
            'message' => 'Phone added successfully',
        ], 201);
    }

    /**
     * Get detailed phone status
     */
    public function show(Request $request, $identifier)
    {
        // Try to find in database first
        $phone = VoipPhone::where('ip', $identifier)
            ->orWhere('mac', $identifier)
            ->orWhere('extension', $identifier)
            ->first();
        
        // Fallback to IP resolution for SIP-only phones
        $ip = $phone ? $phone->ip : $this->resolvePhoneIP($identifier);
        
        if (!$ip) {
            return response()->json([
                'success' => false,
                'error' => 'Phone not found',
            ], 404);
        }

        $credentials = $phone ? $phone->getCredentialsForApi() : $request->input('credentials', []);
        $status = $this->grandstreamService->getPhoneStatus($ip, $credentials);

        // Update phone record if it exists
        if ($phone && $status['success'] ?? false) {
            $phone->update([
                'status' => 'online',
                'last_seen' => now(),
                'model' => $status['model'] ?? $phone->model,
                'firmware' => $status['firmware'] ?? $phone->firmware,
                'mac' => $status['mac'] ?? $phone->mac,
            ]);
        }

        return response()->json([
            'success' => true,
            'phone' => $phone ? $phone->toArray() : null,
            'status' => $status,
        ]);
    }

    /**
     * Update phone
     */
    public function update(Request $request, $id)
    {
        $phone = VoipPhone::findOrFail($id);
        
        $request->validate([
            'ip' => 'sometimes|ip|unique:voip_phones,ip,' . $phone->id,
            'mac' => 'nullable|string|max:17',
            'extension' => 'nullable|string|max:32',
            'name' => 'nullable|string|max:100',
            'vendor' => 'nullable|string|max:50',
            'model' => 'nullable|string|max:50',
            'credentials' => 'nullable|array',
        ]);

        $phone->update($request->only([
            'ip', 'mac', 'extension', 'name', 'vendor', 'model', 'credentials'
        ]));

        return response()->json([
            'success' => true,
            'phone' => $phone,
            'message' => 'Phone updated successfully',
        ]);
    }

    /**
     * Delete phone
     */
    public function destroy($id)
    {
        $phone = VoipPhone::findOrFail($id);
        $phone->delete();

        return response()->json([
            'success' => true,
            'message' => 'Phone deleted successfully',
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
            'confirm_destructive' => 'nullable|boolean',
        ]);

        $ip = $request->input('ip');
        $action = $request->input('action');
        
        // Try to get credentials from database
        $phone = VoipPhone::where('ip', $ip)->first();
        $credentials = $phone ? $phone->getCredentialsForApi() : $request->input('credentials', []);

        // Additional validation for destructive actions
        if ($action === 'factory_reset' && !$request->input('confirm_destructive', false)) {
            return response()->json([
                'success' => false,
                'error' => 'Confirmation required for factory reset',
                'message' => 'Set confirm_destructive to true to proceed',
            ], 400);
        }

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
        
        // Get phone from database to use stored credentials
        $phone = VoipPhone::where('ip', $request->input('ip'))->first();
        $credentials = $phone ? $phone->getCredentialsForApi() : $request->input('credentials', []);
        
        $result = $this->grandstreamService->provisionExtensionToPhone(
            $request->input('ip'),
            $extension->toArray(),
            $request->input('account_number', 1),
            $credentials
        );

        // Update phone record with extension association
        if ($phone && ($result['success'] ?? false)) {
            $phone->update([
                'extension' => $extension->extension_number,
                'last_seen' => now(),
            ]);
        }

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

        // Update phone status based on event
        if (!empty($data['ip'])) {
            $phone = VoipPhone::where('ip', $data['ip'])->first();
            if ($phone) {
                $phone->update(['last_seen' => now()]);
                
                // Update status based on event
                if (in_array($event, ['registration', 'registered'])) {
                    $phone->update(['status' => 'registered']);
                } elseif ($event === 'unregistered') {
                    $phone->update(['status' => 'offline']);
                }
            }
        }

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
     * Get LLDP neighbors (discovered VoIP phones via LLDP protocol)
     */
    public function lldpNeighbors(Request $request)
    {
        try {
            $phones = $this->grandstreamService->discoverPhones();
            
            // Filter for LLDP-discovered devices only
            $lldpDevices = array_filter($phones['devices'] ?? [], function ($device) {
                return ($device['discovery_type'] ?? '') === 'lldp';
            });
            
            return response()->json([
                'success' => true,
                'neighbors' => array_values($lldpDevices),
                'total' => count($lldpDevices),
                'message' => count($lldpDevices) > 0 
                    ? 'LLDP neighbors discovered successfully' 
                    : 'No LLDP neighbors found. Ensure lldpd is running.',
            ]);
        } catch (\Exception $e) {
            Log::warning('LLDP discovery failed', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'neighbors' => [],
                'total' => 0,
                'error' => $e->getMessage(),
                'message' => 'LLDP discovery failed. Ensure lldpd is installed and running.',
            ]);
        }
    }

    /**
     * Get ARP table entries (discovered devices from ARP cache)
     */
    public function arpNeighbors(Request $request)
    {
        try {
            $phones = $this->grandstreamService->discoverPhones();
            
            // Filter for ARP-discovered devices only
            $arpDevices = array_filter($phones['devices'] ?? [], function ($device) {
                return ($device['discovery_type'] ?? '') === 'arp';
            });
            
            return response()->json([
                'success' => true,
                'neighbors' => array_values($arpDevices),
                'total' => count($arpDevices),
                'message' => count($arpDevices) > 0 
                    ? 'ARP neighbors discovered successfully' 
                    : 'No ARP entries found.',
            ]);
        } catch (\Exception $e) {
            Log::warning('ARP discovery failed', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'neighbors' => [],
                'total' => 0,
                'error' => $e->getMessage(),
                'message' => 'ARP discovery failed.',
            ]);
        }
    }

    /**
     * Discover all phones (returns LLDP + ARP + nmap discovered devices)
     */
    public function discover(Request $request)
    {
        try {
            $result = $this->grandstreamService->discoverPhones();
            
            // Auto-add discovered GrandStream phones to database
            foreach ($result['devices'] ?? [] as $device) {
                if (!empty($device['ip']) && ($device['vendor'] ?? '') === 'GrandStream') {
                    VoipPhone::updateOrCreate(
                        ['ip' => $device['ip']],
                        [
                            'mac' => $device['mac'] ?? null,
                            'vendor' => 'grandstream',
                            'model' => $device['model'] ?? null,
                            'discovery_type' => $device['discovery_type'] ?? 'auto',
                            'status' => 'discovered',
                            'last_seen' => now(),
                        ]
                    );
                }
            }
            
            return response()->json([
                'success' => true,
                'devices' => $result['devices'] ?? [],
                'phones' => $result['phones'] ?? [],
                'total' => count($result['devices'] ?? []),
                'message' => 'Discovery completed successfully',
            ]);
        } catch (\Exception $e) {
            Log::warning('Phone discovery failed', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'devices' => [],
                'phones' => [],
                'total' => 0,
                'error' => $e->getMessage(),
                'message' => 'Discovery failed.',
            ]);
        }
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
