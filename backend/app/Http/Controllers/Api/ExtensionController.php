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
        
        // Enrich with real-time status
        foreach ($extensions as $extension) {
            $extension->status = $this->asterisk->getExtensionStatus($extension->extension_number);
        }
        
        return response()->json(['extensions' => $extensions]);
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
        
        // Generate and write PJSIP configuration
        $config = $this->asterisk->generatePjsipEndpoint($extension);
        $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
        
        // Reload Asterisk
        $this->asterisk->reload();
        
        // Broadcast event
        $this->broadcaster->broadcastExtensionCreated($extension->toArray());
        
        return response()->json([
            'message' => 'Extension created successfully',
            'extension' => $extension
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
        
        // Broadcast event
        $this->broadcaster->broadcastExtensionUpdated($extension->toArray());
        
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
        
        // Regenerate configuration
        $config = $this->asterisk->generatePjsipEndpoint($extension);
        $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
        $this->asterisk->reload();
        
        // Broadcast event
        $this->broadcaster->broadcastExtensionUpdated($extension->toArray());
        
        return response()->json([
            'message' => 'Extension status updated',
            'extension' => $extension
        ]);
    }
}
