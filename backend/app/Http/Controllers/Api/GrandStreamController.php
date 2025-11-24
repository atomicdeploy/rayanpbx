<?php

namespace App\Http\Controllers\Api;

use App\Services\GrandStreamProvisioningService;
use App\Models\Extension;
use Illuminate\Http\Request;

class GrandStreamController extends Controller
{
    protected $provisioningService;

    public function __construct(GrandStreamProvisioningService $provisioningService)
    {
        $this->provisioningService = $provisioningService;
    }

    /**
     * List discovered GrandStream devices
     */
    public function listDevices(Request $request)
    {
        $network = $request->input('network', '192.168.1.0/24');
        
        $devices = $this->provisioningService->discoverPhones($network);

        return response()->json([
            'success' => true,
            'devices' => $devices,
        ]);
    }

    /**
     * Scan network for GrandStream phones
     */
    public function scanNetwork(Request $request)
    {
        $request->validate([
            'network' => 'required|string',
        ]);

        $result = $this->provisioningService->discoverPhones($request->network);

        return response()->json([
            'success' => true,
            'scan_result' => $result,
        ]);
    }

    /**
     * Get provisioning configuration for a phone
     */
    public function getProvisioningConfig($mac)
    {
        // This endpoint is called by GrandStream phones themselves
        // Format MAC address
        $mac = strtoupper(str_replace([':', '-'], '', $mac));

        try {
            // Look for existing configuration
            $filename = "cfg{$mac}.xml";
            
            if (\Storage::disk('local')->exists("provisioning/{$filename}")) {
                $content = \Storage::disk('local')->get("provisioning/{$filename}");
                
                return response($content, 200)
                    ->header('Content-Type', 'text/xml');
            }

            return response()->json([
                'error' => 'Configuration not found',
                'mac' => $mac,
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve configuration',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Configure a phone (assign extension, set options)
     */
    public function configurePhone(Request $request, $mac)
    {
        $request->validate([
            'extension_id' => 'required|integer|exists:extensions,id',
            'model' => 'nullable|string',
            'account_number' => 'nullable|integer|min:1|max:6',
            'blf_list' => 'nullable|array',
            'network' => 'nullable|array',
        ]);

        try {
            $extension = Extension::findOrFail($request->extension_id);
            
            $options = [
                'model' => $request->input('model', 'GXP1628'),
                'account_number' => $request->input('account_number', 1),
                'blf_list' => $request->input('blf_list', []),
            ];

            if ($request->has('network')) {
                $options = array_merge($options, $request->network);
            }

            $config = $this->provisioningService->generateConfig($mac, $extension->toArray(), $options);
            
            // Add MAC to config
            $config['mac'] = $mac;
            
            // Convert to XML and store
            $xmlContent = $this->provisioningService->toXML($config);
            $filename = "cfg{$mac}.xml";
            \Storage::disk('local')->put("provisioning/{$filename}", $xmlContent);

            return response()->json([
                'success' => true,
                'message' => 'Phone configured successfully',
                'mac' => $mac,
                'extension' => $extension->extension_number,
                'config_url' => route('grandstream.provision', ['mac' => $mac]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Configuration failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get phone status
     */
    public function getPhoneStatus(Request $request, $mac)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $status = $this->provisioningService->getPhoneStatus(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json([
            'success' => isset($status['status']) && $status['status'] === 'online',
            'mac' => $mac,
            'status' => $status,
        ]);
    }

    /**
     * Reboot phone
     */
    public function rebootPhone(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->provisioningService->rebootPhone(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Factory reset phone
     */
    public function factoryResetPhone(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->provisioningService->factoryResetPhone(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Get phone configuration
     */
    public function getPhoneConfig(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->provisioningService->getPhoneConfig(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Set phone configuration
     */
    public function setPhoneConfig(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'config' => 'required|array',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->provisioningService->setPhoneConfig(
            $request->input('ip'),
            $request->input('config'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Provision extension to phone via direct HTTP
     */
    public function provisionExtensionDirect(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'extension_id' => 'required|integer|exists:extensions,id',
            'account_number' => 'nullable|integer|min:1|max:6',
            'credentials' => 'nullable|array',
        ]);

        $extension = Extension::findOrFail($request->extension_id);
        
        $result = $this->provisioningService->provisionExtensionToPhone(
            $request->input('ip'),
            $extension->toArray(),
            $request->input('account_number', 1),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Assign extension to phone
     */
    public function assignExtension(Request $request)
    {
        $request->validate([
            'mac' => 'required|string',
            'extension_id' => 'required|integer|exists:extensions,id',
        ]);

        try {
            $result = $this->provisioningService->assignExtension(
                $request->mac,
                $request->extension_id
            );

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
     * Get supported phone models
     */
    public function getSupportedModels()
    {
        $models = $this->provisioningService->getSupportedModels();

        return response()->json([
            'success' => true,
            'models' => $models,
        ]);
    }

    /**
     * Get provisioning hooks configuration
     * Returns information about how GrandStream phones should be configured
     */
    public function getProvisioningHooks()
    {
        return response()->json([
            'success' => true,
            'provisioning' => [
                'protocol' => 'HTTP',
                'base_url' => config('rayanpbx.provisioning_base_url', 'http://' . request()->getHost() . '/api/grandstream/provision'),
                'method' => 'GET',
                'format' => 'XML',
                'auth_required' => false,
            ],
            'models' => $this->provisioningService->getSupportedModels(),
            'configuration_url_format' => '{base_url}/{mac}.xml',
            'example' => config('rayanpbx.provisioning_base_url', 'http://' . request()->getHost()) . '/api/grandstream/provision/000B82123456',
            'phone_setup' => [
                'step_1' => 'Access phone web interface',
                'step_2' => 'Navigate to Maintenance > Upgrade',
                'step_3' => 'Set Config Server Path to: ' . config('rayanpbx.provisioning_base_url'),
                'step_4' => 'Set Firmware Server Path (optional)',
                'step_5' => 'Click Update to provision',
            ],
        ]);
    }
    
    /**
     * Ping a phone to check if it's reachable
     */
    public function pingPhone(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
        ]);
        
        $online = $this->provisioningService->pingHost($request->ip);
        
        return response()->json([
            'success' => true,
            'ip' => $request->ip,
            'online' => $online,
        ]);
    }
    
    /**
     * Check reachability of multiple phones
     */
    public function checkReachability(Request $request)
    {
        $request->validate([
            'phones' => 'required|array',
            'phones.*.ip' => 'required|ip',
        ]);
        
        $phones = $this->provisioningService->checkPhoneReachability($request->phones);
        
        return response()->json([
            'success' => true,
            'phones' => $phones,
        ]);
    }
}
