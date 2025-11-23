<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Models\Trunk;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    /**
     * Get system status overview
     */
    public function index()
    {
        $extensionsTotal = Extension::count();
        $extensionsActive = Extension::where('enabled', true)->count();
        
        $trunksTotal = Trunk::count();
        $trunksActive = Trunk::where('enabled', true)->count();
        
        return response()->json([
            'status' => [
                'asterisk' => $this->checkAsteriskStatus(),
                'database' => 'connected',
                'extensions' => [
                    'total' => $extensionsTotal,
                    'active' => $extensionsActive,
                    'registered' => 0, // Would be updated by AMI events
                ],
                'trunks' => [
                    'total' => $trunksTotal,
                    'active' => $trunksActive,
                    'online' => 0, // Would be updated by AMI events
                ],
            ]
        ]);
    }
    
    /**
     * Get extensions status
     */
    public function extensions()
    {
        $extensions = Extension::where('enabled', true)->get();
        
        $status = $extensions->map(function ($ext) {
            return [
                'extension' => $ext->extension_number,
                'name' => $ext->name,
                'status' => cache()->get("extension_status_{$ext->extension_number}", 'offline'),
                'ip' => cache()->get("extension_ip_{$ext->extension_number}", 'N/A'),
            ];
        });
        
        return response()->json(['extensions' => $status]);
    }
    
    /**
     * Get trunks status
     */
    public function trunks()
    {
        $trunks = Trunk::where('enabled', true)->get();
        
        $status = $trunks->map(function ($trunk) {
            return [
                'name' => $trunk->name,
                'host' => $trunk->host,
                'status' => cache()->get("trunk_status_{$trunk->name}", 'unknown'),
                'latency' => cache()->get("trunk_latency_{$trunk->name}", 'N/A'),
            ];
        });
        
        return response()->json(['trunks' => $status]);
    }
    
    /**
     * Check Asterisk status
     */
    private function checkAsteriskStatus()
    {
        try {
            $socket = @fsockopen(
                config('rayanpbx.asterisk.ami_host', '127.0.0.1'),
                config('rayanpbx.asterisk.ami_port', 5038),
                $errno,
                $errstr,
                2
            );
            
            if ($socket) {
                fclose($socket);
                return 'running';
            }
            
            return 'stopped';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}
