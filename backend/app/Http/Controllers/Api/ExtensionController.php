<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Adapters\AsteriskAdapter;
use App\Services\EventBroadcastService;
use Illuminate\Http\Request;

class ExtensionController extends Controller
{
    private $asterisk;
    private $broadcaster;
    
    public function __construct(AsteriskAdapter $asterisk, EventBroadcastService $broadcaster)
    {
        $this->asterisk = $asterisk;
        $this->broadcaster = $broadcaster;
    }
    
    /**
     * List all extensions
     */
    public function index()
    {
        $extensions = Extension::orderBy('extension_number')->get();
        
        // Get all PJSIP endpoints from Asterisk
        $asteriskEndpoints = $this->asterisk->getAllPjsipEndpoints();
        
        // Enrich with real-time status from Asterisk
        foreach ($extensions as $extension) {
            $registrationStatus = $this->asterisk->getEndpointRegistrationStatus($extension->extension_number);
            $extension->registered = $registrationStatus['registered'];
            $extension->asterisk_status = $registrationStatus['status'];
            $extension->contact_count = $registrationStatus['contacts'];
            
            // Add additional details if available
            if (isset($registrationStatus['details']['contacts'][0])) {
                $contact = $registrationStatus['details']['contacts'][0];
                if (preg_match('/@([\d.]+):(\d+)/', $contact['uri'], $matches)) {
                    $extension->ip_address = $matches[1];
                    $extension->port = $matches[2];
                }
            }
        }
        
        return response()->json([
            'extensions' => $extensions,
            'asterisk_endpoints' => $asteriskEndpoints,
        ]);
    }
    
    /**
     * Create new extension
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'extension_number' => 'required|string|unique:extensions|min:2|max:20',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email',
            'secret' => 'required|string|min:8',
            'enabled' => 'boolean',
            'context' => 'string',
            'transport' => 'string|in:udp,tcp,tls,transport-udp,transport-tcp,transport-tls',
            'codecs' => 'nullable|array',
            'codecs.*' => 'string|in:ulaw,alaw,g722,g729,opus,gsm,ilbc,speex,h264,vp8',
            'max_contacts' => 'integer|min:1|max:10',
            'direct_media' => 'string|in:yes,no',
            'qualify_frequency' => 'integer|min:0|max:3600',
            'caller_id' => 'nullable|string',
            'voicemail_enabled' => 'boolean',
            'notes' => 'nullable|string',
        ]);
        
        // Hash the secret for storage
        $validated['secret'] = bcrypt($validated['secret']);
        
        // Set defaults for new PJSIP options
        $validated['direct_media'] = $validated['direct_media'] ?? 'no';
        $validated['qualify_frequency'] = $validated['qualify_frequency'] ?? 60;
        $validated['codecs'] = $validated['codecs'] ?? ['ulaw', 'alaw', 'g722'];
        
        $extension = Extension::create($validated);
        
        // Ensure transport configuration exists
        $this->asterisk->ensureTransportConfig();
        
        // Generate and write PJSIP configuration
        $config = $this->asterisk->generatePjsipEndpoint($extension);
        $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
        
        // Regenerate internal dialplan for all extensions
        $allExtensions = Extension::where('enabled', true)->get();
        $dialplanConfig = $this->asterisk->generateInternalDialplan($allExtensions);
        $this->asterisk->writeDialplanConfig($dialplanConfig, "RayanPBX Internal Extensions");
        
        // Reload Asterisk
        $reloadSuccess = $this->asterisk->reload();
        
        // Verify endpoint was created in Asterisk
        $verified = $this->asterisk->verifyEndpointExists($extension->extension_number);
        
        // Broadcast event
        $this->broadcaster->broadcastExtensionCreated($extension->toArray());
        
        return response()->json([
            'message' => 'Extension created successfully',
            'extension' => $extension,
            'asterisk_verified' => $verified,
            'reload_success' => $reloadSuccess,
        ], 201);
    }
    
    /**
     * Show extension details
     */
    public function show($id)
    {
        $extension = Extension::findOrFail($id);
        $extension->status = $this->asterisk->getExtensionStatus($extension->extension_number);
        
        return response()->json(['extension' => $extension]);
    }
    
    /**
     * Update extension
     */
    public function update(Request $request, $id)
    {
        $extension = Extension::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'string|max:255',
            'email' => 'nullable|email',
            'secret' => 'nullable|string|min:8',
            'enabled' => 'boolean',
            'context' => 'string',
            'transport' => 'string|in:udp,tcp,tls,transport-udp,transport-tcp,transport-tls',
            'codecs' => 'nullable|array',
            'codecs.*' => 'string|in:ulaw,alaw,g722,g729,opus,gsm,ilbc,speex,h264,vp8',
            'max_contacts' => 'integer|min:1|max:10',
            'direct_media' => 'string|in:yes,no',
            'qualify_frequency' => 'integer|min:0|max:3600',
            'caller_id' => 'nullable|string',
            'voicemail_enabled' => 'boolean',
            'notes' => 'nullable|string',
        ]);
        
        if (isset($validated['secret'])) {
            $validated['secret'] = bcrypt($validated['secret']);
        }
        
        $extension->update($validated);
        
        $configWriteSuccess = true;
        $dialplanWriteSuccess = true;
        $reloadSuccess = true;
        $configError = null;
        
        try {
            if ($extension->enabled) {
                // Extension is enabled - write PJSIP config
                $config = $this->asterisk->generatePjsipEndpoint($extension);
                $configWriteSuccess = $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
                if (!$configWriteSuccess) {
                    $configError = 'Failed to write PJSIP configuration';
                }
            } else {
                // Extension is disabled - remove PJSIP config
                $configWriteSuccess = $this->asterisk->removePjsipConfig("Extension {$extension->extension_number}");
                if (!$configWriteSuccess) {
                    $configError = 'Failed to remove PJSIP configuration';
                }
            }
            
            // Regenerate dialplan for all enabled extensions
            $allExtensions = Extension::where('enabled', true)->get();
            $dialplanConfig = $this->asterisk->generateInternalDialplan($allExtensions);
            $dialplanWriteSuccess = $this->asterisk->writeDialplanConfig($dialplanConfig, "RayanPBX Internal Extensions");
            
            if (!$dialplanWriteSuccess) {
                $configError = ($configError ? $configError . '; ' : '') . 'Failed to write dialplan configuration';
            }
            
            $reloadSuccess = $this->asterisk->reload();
            
            if (!$reloadSuccess) {
                $configError = ($configError ? $configError . '; ' : '') . 'Failed to reload Asterisk';
            }
        } catch (\Exception $e) {
            $configError = 'Exception during configuration update: ' . $e->getMessage();
            $reloadSuccess = false;
        }
        
        // Broadcast event
        $this->broadcaster->broadcastExtensionUpdated($extension->toArray());
        
        return response()->json([
            'message' => 'Extension updated successfully',
            'extension' => $extension,
            'config_write_success' => $configWriteSuccess && $dialplanWriteSuccess,
            'reload_success' => $reloadSuccess,
            'error' => $configError,
        ]);
    }
    
    /**
     * Delete extension
     */
    public function destroy($id)
    {
        $extension = Extension::findOrFail($id);
        $extensionNumber = $extension->extension_number;
        
        // Remove from PJSIP config
        $this->asterisk->removePjsipConfig("Extension {$extensionNumber}");
        
        $extension->delete();
        
        // Reload Asterisk
        $this->asterisk->reload();
        
        // Broadcast event
        $this->broadcaster->broadcastExtensionDeleted($id, $extensionNumber);
        
        return response()->json([
            'message' => 'Extension deleted successfully'
        ]);
    }
    
    /**
     * Toggle extension enabled/disabled
     */
    public function toggle($id)
    {
        $extension = Extension::findOrFail($id);
        $extension->enabled = !$extension->enabled;
        $extension->save();
        
        $configWriteSuccess = true;
        $dialplanWriteSuccess = true;
        $reloadSuccess = true;
        $configError = null;
        
        try {
            // Regenerate configuration
            if ($extension->enabled) {
                // Extension is being enabled - write PJSIP config
                $config = $this->asterisk->generatePjsipEndpoint($extension);
                $configWriteSuccess = $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
                if (!$configWriteSuccess) {
                    $configError = 'Failed to write PJSIP configuration';
                }
            } else {
                // Extension is being disabled - remove PJSIP config
                $configWriteSuccess = $this->asterisk->removePjsipConfig("Extension {$extension->extension_number}");
                if (!$configWriteSuccess) {
                    $configError = 'Failed to remove PJSIP configuration';
                }
            }
            
            // Regenerate dialplan for all enabled extensions
            $allExtensions = Extension::where('enabled', true)->get();
            $dialplanConfig = $this->asterisk->generateInternalDialplan($allExtensions);
            $dialplanWriteSuccess = $this->asterisk->writeDialplanConfig($dialplanConfig, "RayanPBX Internal Extensions");
            
            if (!$dialplanWriteSuccess) {
                $configError = ($configError ? $configError . '; ' : '') . 'Failed to write dialplan configuration';
            }
            
            // Reload Asterisk
            $reloadSuccess = $this->asterisk->reload();
            
            if (!$reloadSuccess) {
                $configError = ($configError ? $configError . '; ' : '') . 'Failed to reload Asterisk';
            }
        } catch (\Exception $e) {
            $configError = 'Exception during configuration update: ' . $e->getMessage();
            $reloadSuccess = false;
        }
        
        // Broadcast event
        $this->broadcaster->broadcastExtensionUpdated($extension->toArray());
        
        $statusText = $extension->enabled ? 'enabled' : 'disabled';
        
        return response()->json([
            'message' => "Extension {$statusText} successfully",
            'extension' => $extension,
            'config_write_success' => $configWriteSuccess && $dialplanWriteSuccess,
            'reload_success' => $reloadSuccess,
            'error' => $configError,
        ]);
    }
    
    /**
     * Verify extension in Asterisk
     */
    public function verify($id)
    {
        $extension = Extension::findOrFail($id);
        
        // Get detailed status from Asterisk
        $registrationStatus = $this->asterisk->getEndpointRegistrationStatus($extension->extension_number);
        $endpointDetails = $this->asterisk->getPjsipEndpoint($extension->extension_number);
        
        return response()->json([
            'extension' => $extension,
            'exists_in_asterisk' => $endpointDetails !== null,
            'registration_status' => $registrationStatus,
            'endpoint_details' => $endpointDetails,
        ]);
    }
    
    /**
     * Get all endpoints from Asterisk (not just DB)
     */
    public function asteriskEndpoints()
    {
        $asteriskEndpoints = $this->asterisk->getAllPjsipEndpoints();
        $dbExtensions = Extension::pluck('extension_number')->toArray();
        
        // Mark which endpoints are managed by RayanPBX
        foreach ($asteriskEndpoints as &$endpoint) {
            $endpoint['managed'] = in_array($endpoint['name'], $dbExtensions);
        }
        
        return response()->json([
            'endpoints' => $asteriskEndpoints,
            'total' => count($asteriskEndpoints),
        ]);
    }
    
    /**
     * Get diagnostics and setup guide for an extension
     */
    public function diagnostics($id)
    {
        $extension = Extension::findOrFail($id);
        
        // Get detailed status from Asterisk
        $registrationStatus = $this->asterisk->getEndpointRegistrationStatus($extension->extension_number);
        $endpointDetails = $this->asterisk->getPjsipEndpoint($extension->extension_number);
        
        // Generate SIP client setup guide
        $setupGuide = [
            'extension' => $extension->extension_number,
            'username' => $extension->extension_number,
            'server' => env('PBX_SERVER_IP') ?: env('APP_URL') ?: 'your-pbx-server',
            'port' => 5060,
            'transport' => 'UDP',
            'context' => $extension->context ?? 'from-internal',
        ];
        
        // Popular SIP clients
        $sipClients = [
            [
                'name' => 'MicroSIP',
                'platform' => 'Windows',
                'url' => 'https://www.microsip.org/',
                'description' => 'Lightweight SIP softphone for Windows',
            ],
            [
                'name' => 'Linphone',
                'platform' => 'Cross-platform',
                'url' => 'https://www.linphone.org/',
                'description' => 'Open source VoIP client for desktop and mobile',
            ],
            [
                'name' => 'Zoiper',
                'platform' => 'Cross-platform',
                'url' => 'https://www.zoiper.com/',
                'description' => 'Free and premium SIP softphone',
            ],
            [
                'name' => 'GrandStream',
                'platform' => 'Hardware',
                'url' => 'https://www.grandstream.com/',
                'description' => 'Enterprise IP phones',
            ],
            [
                'name' => 'Yealink',
                'platform' => 'Hardware',
                'url' => 'https://www.yealink.com/',
                'description' => 'Professional IP phones',
            ],
        ];
        
        // Troubleshooting tips based on current state
        $troubleshooting = [];
        
        if (!$extension->enabled) {
            $troubleshooting[] = [
                'severity' => 'error',
                'message' => 'Extension is disabled',
                'solution' => 'Enable the extension before attempting registration',
                'action' => 'enable_extension',
            ];
        }
        
        if (!$registrationStatus['registered']) {
            $troubleshooting[] = [
                'severity' => 'warning',
                'message' => 'Extension is not registered',
                'solution' => 'Configure a SIP client with the provided credentials',
                'action' => null,
            ];
            
            $troubleshooting[] = [
                'severity' => 'info',
                'message' => 'Check network connectivity',
                'solution' => 'Ensure the SIP client can reach the PBX server on port 5060',
                'action' => null,
            ];
            
            $troubleshooting[] = [
                'severity' => 'info',
                'message' => 'Verify credentials',
                'solution' => 'Ensure the extension number and password match your configuration',
                'action' => null,
            ];
        }
        
        if ($endpointDetails === null) {
            $troubleshooting[] = [
                'severity' => 'error',
                'message' => 'Endpoint not found in Asterisk',
                'solution' => 'Reload Asterisk configuration to apply changes',
                'action' => 'reload_asterisk',
            ];
        }
        
        // Test call instructions
        $testInstructions = [
            [
                'step' => 1,
                'action' => 'Register SIP client',
                'description' => 'Configure your SIP client with the provided credentials and verify registration status shows "Registered"',
            ],
            [
                'step' => 2,
                'action' => 'Verify registration',
                'description' => 'Check that the extension shows as online in the Web UI or use the verify endpoint',
            ],
            [
                'step' => 3,
                'action' => 'Place test call',
                'description' => 'Dial another extension number to test call establishment',
            ],
            [
                'step' => 4,
                'action' => 'Verify audio',
                'description' => 'Ensure two-way audio is working correctly during the call',
            ],
            [
                'step' => 5,
                'action' => 'Test receiving calls',
                'description' => 'Have another extension call this one to verify incoming calls work',
            ],
        ];
        
        return response()->json([
            'extension' => $extension,
            'registration_status' => $registrationStatus,
            'endpoint_details' => $endpointDetails,
            'setup_guide' => $setupGuide,
            'sip_clients' => $sipClients,
            'troubleshooting' => $troubleshooting,
            'test_instructions' => $testInstructions,
            'api_endpoints' => [
                'verify' => route('api.extensions.verify', ['id' => $id]),
                'endpoints' => route('api.extensions.asterisk.endpoints'),
            ],
        ]);
    }
}
