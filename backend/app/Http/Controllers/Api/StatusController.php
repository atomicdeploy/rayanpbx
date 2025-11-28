<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Models\Trunk;
use App\Services\AsteriskConfigGitService;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    private AsteriskConfigGitService $gitService;

    public function __construct()
    {
        $this->gitService = new AsteriskConfigGitService();
    }

    /**
     * Get system status overview
     */
    public function index()
    {
        $extensionsTotal = Extension::count();
        $extensionsActive = Extension::where('enabled', true)->count();
        
        $trunksTotal = Trunk::count();
        $trunksActive = Trunk::where('enabled', true)->count();
        
        // Get Git status for /etc/asterisk
        $gitStatus = $this->gitService->getStatus();
        
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
                'config_git' => [
                    'is_repo' => $gitStatus['is_repo'],
                    'is_dirty' => $gitStatus['is_dirty'] ?? false,
                    'change_count' => $gitStatus['change_count'] ?? 0,
                ],
            ]
        ]);
    }
    
    /**
     * Get Git status for Asterisk configuration
     */
    public function gitStatus()
    {
        $status = $this->gitService->getStatus();
        $dirtyState = $this->gitService->getDirtyState();
        
        return response()->json([
            'git_status' => [
                'is_repo' => $status['is_repo'],
                'is_dirty' => $dirtyState['is_dirty'],
                'change_count' => $dirtyState['change_count'],
                'message' => $dirtyState['message'],
                'uncommitted_changes' => $dirtyState['changes'],
                'commit_count' => $status['commit_count'],
                'last_commit' => $status['last_commit'],
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
