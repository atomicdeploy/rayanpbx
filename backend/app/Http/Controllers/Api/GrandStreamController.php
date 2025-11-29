<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GrandStreamProvisioningService;
use App\Services\GrandStreamCTIService;
use App\Models\Extension;
use Illuminate\Http\Request;

class GrandStreamController extends Controller
{
    protected $provisioningService;
    protected $ctiService;

    public function __construct(
        GrandStreamProvisioningService $provisioningService,
        GrandStreamCTIService $ctiService
    ) {
        $this->provisioningService = $provisioningService;
        $this->ctiService = $ctiService;
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

    /**
     * Check Action URL configuration on a phone
     * Returns current values and whether they match expected RayanPBX values
     */
    public function checkActionUrls(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->provisioningService->checkActionUrls(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Update Action URLs on a phone
     * Configures the phone to send webhooks to RayanPBX
     */
    public function updateActionUrls(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
            'force' => 'nullable|boolean',
        ]);

        $result = $this->provisioningService->updateActionUrls(
            $request->input('ip'),
            $request->input('credentials', []),
            $request->input('force', false)
        );

        // If requires confirmation, return 409 Conflict
        if (isset($result['requires_confirmation']) && $result['requires_confirmation']) {
            return response()->json($result, 409);
        }

        return response()->json($result);
    }

    /**
     * Complete phone provisioning with extension and Action URLs
     */
    public function provisionComplete(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'extension_id' => 'required|integer|exists:extensions,id',
            'account_number' => 'nullable|integer|min:1|max:6',
            'credentials' => 'nullable|array',
            'force_action_urls' => 'nullable|boolean',
        ]);

        $extension = Extension::findOrFail($request->extension_id);

        $result = $this->provisioningService->provisionPhoneComplete(
            $request->input('ip'),
            $extension->toArray(),
            $request->input('account_number', 1),
            $request->input('credentials', []),
            $request->input('force_action_urls', false)
        );

        // If Action URLs require confirmation, return 409 Conflict
        if (isset($result['action_urls_result']['requires_confirmation']) && 
            $result['action_urls_result']['requires_confirmation']) {
            return response()->json([
                'success' => false,
                'message' => 'Extension provisioned but Action URLs require confirmation',
                'extension_provisioned' => true,
                'action_urls_result' => $result['action_urls_result'],
            ], 409);
        }

        return response()->json($result);
    }

    // ========================================================================
    // CTI/CSTA Operations
    // ========================================================================

    /**
     * Get phone CTI status including call states
     */
    public function getCTIStatus(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->getPhoneStatus(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Get line/account status
     */
    public function getLineStatus(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'line_id' => 'required|integer|min:1|max:6',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->getLineStatus(
            $request->input('ip'),
            $request->input('line_id'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Execute CTI phone operation (accept, reject, hold, etc.)
     */
    public function executeCTIOperation(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'operation' => 'required|string|in:accept_call,reject_call,end_call,hold,unhold,mute,unmute,dial,redial,dtmf,blind_transfer,attended_transfer,conference,dnd,forward,intercom,paging,park,pickup',
            'line_id' => 'nullable|integer|min:1|max:6',
            'credentials' => 'nullable|array',
            // Operation-specific parameters
            'number' => 'nullable|string',
            'target' => 'nullable|string',
            'digits' => 'nullable|string',
            'value' => 'nullable|string',
            'slot' => 'nullable|string',
            'extension' => 'nullable|string',
            'forward_type' => 'nullable|string|in:unconditional,busy,noanswer',
        ]);

        $ip = $request->input('ip');
        $operation = $request->input('operation');
        $lineId = $request->input('line_id');
        $credentials = $request->input('credentials', []);

        $result = match ($operation) {
            'accept_call' => $this->ctiService->acceptCall($ip, $lineId, $credentials),
            'reject_call' => $this->ctiService->rejectCall($ip, $lineId, $credentials),
            'end_call' => $this->ctiService->endCall($ip, $lineId, $credentials),
            'hold' => $this->ctiService->holdCall($ip, $lineId, $credentials),
            'unhold' => $this->ctiService->unholdCall($ip, $lineId, $credentials),
            'mute' => $this->ctiService->mute($ip, $lineId, $credentials),
            'unmute' => $this->ctiService->unmute($ip, $lineId, $credentials),
            'dial' => $this->ctiService->dial($ip, $request->input('number'), $lineId, $credentials),
            'redial' => $this->ctiService->redial($ip, $lineId, $credentials),
            'dtmf' => $this->ctiService->sendDTMF($ip, $request->input('digits'), $lineId, $credentials),
            'blind_transfer' => $this->ctiService->blindTransfer($ip, $request->input('target'), $lineId, $credentials),
            'attended_transfer' => $this->ctiService->attendedTransfer($ip, $request->input('target'), $lineId, $credentials),
            'conference' => $this->ctiService->conference($ip, $lineId, $credentials),
            'dnd' => $this->ctiService->setDND($ip, $request->input('value') === '1' || $request->input('value') === 'true', $credentials),
            'forward' => $this->ctiService->setForward(
                $ip,
                $request->input('value') === '1' || $request->input('value') === 'true',
                $request->input('target'),
                $request->input('forward_type'),
                $credentials
            ),
            'intercom' => $this->ctiService->intercom($ip, $request->input('number'), $lineId, $credentials),
            'paging' => $this->ctiService->paging($ip, $request->input('number'), $lineId, $credentials),
            'park' => $this->ctiService->parkCall($ip, $request->input('slot'), $lineId, $credentials),
            'pickup' => $this->ctiService->pickupCall($ip, $request->input('extension'), $credentials),
            default => ['success' => false, 'error' => 'Unknown operation'],
        };

        return response()->json($result);
    }

    /**
     * Display message on phone LCD
     */
    public function displayLCDMessage(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'message' => 'required|string|max:128',
            'duration' => 'nullable|integer|min:1|max:300',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->displayLCDMessage(
            $request->input('ip'),
            $request->input('message'),
            $request->input('duration'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Take screenshot of phone display
     */
    public function takeScreenshot(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->takeScreenshot(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Enable CTI features on phone
     */
    public function enableCTI(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->enableCTI(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Disable CTI features on phone
     */
    public function disableCTI(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->disableCTI(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Enable SNMP monitoring on phone
     */
    public function enableSNMP(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
            'snmp_config' => 'nullable|array',
            'snmp_config.community' => 'nullable|string',
            'snmp_config.trap_server' => 'nullable|ip',
            'snmp_config.trap_port' => 'nullable|integer|min:1|max:65535',
            'snmp_config.version' => 'nullable|string|in:v1,v2c,v3',
        ]);

        $snmpConfig = $request->input('snmp_config', [
            'community' => 'public',
            'version' => 'v2c',
        ]);

        $result = $this->ctiService->enableSNMP(
            $request->input('ip'),
            $snmpConfig,
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Disable SNMP monitoring on phone
     */
    public function disableSNMP(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->disableSNMP(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Get SNMP configuration status
     */
    public function getSNMPStatus(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->getSNMPStatus(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Provision CTI and SNMP features on phone
     * This enables CTI API access and optionally SNMP monitoring
     */
    public function provisionCTIFeatures(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
            'enable_cti' => 'nullable|boolean',
            'enable_snmp' => 'nullable|boolean',
            'snmp_config' => 'nullable|array',
        ]);

        $options = [
            'enable_cti' => $request->input('enable_cti', true),
            'enable_snmp' => $request->input('enable_snmp', true),
            'snmp_config' => $request->input('snmp_config', [
                'community' => 'public',
                'version' => 'v2c',
            ]),
        ];

        $result = $this->ctiService->provisionCTIFeatures(
            $request->input('ip'),
            $options,
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Test CTI and SNMP features
     */
    public function testCTIFeatures(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->testCTIFeatures(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Trigger phone to re-provision
     */
    public function triggerProvision(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->triggerProvision(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Trigger firmware upgrade on phone
     */
    public function triggerUpgrade(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'firmware_url' => 'nullable|url',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->ctiService->triggerUpgrade(
            $request->input('ip'),
            $request->input('firmware_url'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    // ========================================================================
    // SIP Codec Priority Configuration
    // ========================================================================

    /**
     * Get available codecs and configuration info
     */
    public function getCodecInfo()
    {
        $info = $this->provisioningService->getCodecConfigInfo();

        return response()->json([
            'success' => true,
            ...$info,
        ]);
    }

    /**
     * Get current codec priority configuration from phone
     */
    public function getCodecConfig(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->provisioningService->getCodecConfig(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Set codec priority configuration on phone
     */
    public function setCodecConfig(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'codec_order' => 'required|array|min:1|max:7',
            'codec_order.*' => 'required|string',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->provisioningService->setCodecConfig(
            $request->input('ip'),
            $request->input('codec_order'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }

    /**
     * Apply recommended codec order to phone
     */
    public function applyRecommendedCodecOrder(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'credentials' => 'nullable|array',
        ]);

        $result = $this->provisioningService->applyRecommendedCodecOrder(
            $request->input('ip'),
            $request->input('credentials', [])
        );

        return response()->json($result);
    }
}
