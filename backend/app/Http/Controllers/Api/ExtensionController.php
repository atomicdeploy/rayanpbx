<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Adapters\AsteriskAdapter;
use Illuminate\Http\Request;

class ExtensionController extends Controller
{
    private $asterisk;
    
    public function __construct(AsteriskAdapter $asterisk)
    {
        $this->asterisk = $asterisk;
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
            'transport' => 'string|in:udp,tcp,tls',
            'codecs' => 'nullable|array',
            'max_contacts' => 'integer|min:1|max:10',
            'caller_id' => 'nullable|string',
            'voicemail_enabled' => 'boolean',
            'notes' => 'nullable|string',
        ]);
        
        // Hash the secret for storage
        $validated['secret'] = bcrypt($validated['secret']);
        
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
            'transport' => 'string|in:udp,tcp,tls',
            'codecs' => 'nullable|array',
            'max_contacts' => 'integer|min:1|max:10',
            'caller_id' => 'nullable|string',
            'voicemail_enabled' => 'boolean',
            'notes' => 'nullable|string',
        ]);
        
        if (isset($validated['secret'])) {
            $validated['secret'] = bcrypt($validated['secret']);
        }
        
        $extension->update($validated);
        
        // Regenerate configuration
        $config = $this->asterisk->generatePjsipEndpoint($extension);
        $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
        $this->asterisk->reload();
        
        return response()->json([
            'message' => 'Extension updated successfully',
            'extension' => $extension
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
        
        // Regenerate configuration
        $config = $this->asterisk->generatePjsipEndpoint($extension);
        $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
        
        // Regenerate dialplan for all enabled extensions
        $allExtensions = Extension::where('enabled', true)->get();
        $dialplanConfig = $this->asterisk->generateInternalDialplan($allExtensions);
        $this->asterisk->writeDialplanConfig($dialplanConfig, "RayanPBX Internal Extensions");
        
        $this->asterisk->reload();
        
        return response()->json([
            'message' => 'Extension status updated',
            'extension' => $extension
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
}
