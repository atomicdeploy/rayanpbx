<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trunk;
use App\Adapters\AsteriskAdapter;
use App\Services\EventBroadcastService;
use Illuminate\Http\Request;

class TrunkController extends Controller
{
    private $asterisk;
    private $broadcaster;
    
    public function __construct(AsteriskAdapter $asterisk, EventBroadcastService $broadcaster)
    {
        $this->asterisk = $asterisk;
        $this->broadcaster = $broadcaster;
    }
    
    /**
     * List all trunks
     */
    public function index()
    {
        $trunks = Trunk::orderBy('priority')->get();
        
        return response()->json(['trunks' => $trunks]);
    }
    
    /**
     * Create new trunk
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:trunks|max:255',
            'type' => 'string|in:peer,user,friend',
            'host' => 'required|string',
            'port' => 'integer|min:1|max:65535',
            'username' => 'nullable|string',
            'secret' => 'nullable|string',
            'enabled' => 'boolean',
            'transport' => 'string|in:udp,tcp,tls',
            'codecs' => 'nullable|array',
            'context' => 'string',
            'priority' => 'integer|min:1',
            'prefix' => 'string',
            'strip_digits' => 'integer|min:0',
            'max_channels' => 'integer|min:1',
            'notes' => 'nullable|string',
        ]);
        
        if (isset($validated['secret'])) {
            $validated['secret'] = bcrypt($validated['secret']);
        }
        
        $trunk = Trunk::create($validated);
        
        // Generate and write PJSIP configuration
        $config = $this->asterisk->generatePjsipTrunk($trunk);
        $this->asterisk->writePjsipConfig($config, "Trunk {$trunk->name}");
        
        // Regenerate dialplan
        $this->regenerateDialplan();
        
        // Reload Asterisk
        $this->asterisk->reload();
        
        // Broadcast event
        $this->broadcaster->broadcastTrunkCreated($trunk->toArray());
        
        return response()->json([
            'message' => 'Trunk created successfully',
            'trunk' => $trunk
        ], 201);
    }
    
    /**
     * Show trunk details
     */
    public function show($id)
    {
        $trunk = Trunk::findOrFail($id);
        
        return response()->json(['trunk' => $trunk]);
    }
    
    /**
     * Update trunk
     */
    public function update(Request $request, $id)
    {
        $trunk = Trunk::findOrFail($id);
        
        $validated = $request->validate([
            'type' => 'string|in:peer,user,friend',
            'host' => 'string',
            'port' => 'integer|min:1|max:65535',
            'username' => 'nullable|string',
            'secret' => 'nullable|string',
            'enabled' => 'boolean',
            'transport' => 'string|in:udp,tcp,tls',
            'codecs' => 'nullable|array',
            'context' => 'string',
            'priority' => 'integer|min:1',
            'prefix' => 'string',
            'strip_digits' => 'integer|min:0',
            'max_channels' => 'integer|min:1',
            'notes' => 'nullable|string',
        ]);
        
        if (isset($validated['secret'])) {
            $validated['secret'] = bcrypt($validated['secret']);
        }
        
        $trunk->update($validated);
        
        // Regenerate configuration
        $config = $this->asterisk->generatePjsipTrunk($trunk);
        $this->asterisk->writePjsipConfig($config, "Trunk {$trunk->name}");
        
        // Regenerate dialplan
        $this->regenerateDialplan();
        
        // Reload Asterisk
        $this->asterisk->reload();
        
        // Broadcast event
        $this->broadcaster->broadcastTrunkUpdated($trunk->toArray());
        
        return response()->json([
            'message' => 'Trunk updated successfully',
            'trunk' => $trunk
        ]);
    }
    
    /**
     * Delete trunk
     */
    public function destroy($id)
    {
        $trunk = Trunk::findOrFail($id);
        $trunkName = $trunk->name;
        
        // Remove from PJSIP config
        $this->asterisk->removePjsipConfig("Trunk {$trunkName}");
        
        $trunk->delete();
        
        // Regenerate dialplan
        $this->regenerateDialplan();
        
        // Reload Asterisk
        $this->asterisk->reload();
        
        // Broadcast event
        $this->broadcaster->broadcastTrunkDeleted($id, $trunkName);
        
        return response()->json([
            'message' => 'Trunk deleted successfully'
        ]);
    }
    
    /**
     * Regenerate dialplan for all trunks
     */
    private function regenerateDialplan()
    {
        $trunks = Trunk::where('enabled', true)
            ->orderBy('priority')
            ->get();
        
        $dialplan = $this->asterisk->generateDialplan($trunks);
        
        // Write to extensions.conf using the adapter's method
        $extensionsConfig = config('rayanpbx.asterisk.extensions_config', '/etc/asterisk/extensions.conf');
        
        try {
            $config = \App\Helpers\AsteriskConfig::parseFile($extensionsConfig);
            
            if ($config === null) {
                $config = new \App\Helpers\AsteriskConfig($extensionsConfig);
                $config->headerLines = [
                    '; RayanPBX Dialplan Configuration',
                    '; Generated by RayanPBX',
                    '',
                ];
            }

            // Remove existing from-internal section and add the new one
            $config->removeSectionsByName('from-internal');
            
            // Parse the new dialplan content and add it
            $newContent = \App\Helpers\AsteriskConfig::parseContent($dialplan);
            foreach ($newContent->sections as $section) {
                $config->addSection($section);
            }
            
            $config->save();
        } catch (\Exception $e) {
            report($e);
        }
    }
}
