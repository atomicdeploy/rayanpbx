<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AsteriskStatusService;
use App\Adapters\AsteriskAdapter;
use Illuminate\Http\Request;

class AsteriskStatusController extends Controller
{
    private AsteriskStatusService $asteriskStatus;
    private AsteriskAdapter $asterisk;

    public function __construct(AsteriskStatusService $asteriskStatus, AsteriskAdapter $asterisk)
    {
        $this->asteriskStatus = $asteriskStatus;
        $this->asterisk = $asterisk;
    }

    /**
     * Get detailed endpoint status
     */
    public function getEndpointStatus(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|string|max:50',
        ]);

        $status = $this->asteriskStatus->getEndpointDetails($validated['endpoint']);

        return response()->json([
            'success' => true,
            'endpoint' => $status,
        ]);
    }

    /**
     * Get all registered endpoints
     */
    public function getAllEndpoints()
    {
        $endpoints = $this->asteriskStatus->getAllRegisteredEndpoints();

        return response()->json([
            'success' => true,
            'endpoints' => $endpoints,
            'count' => count($endpoints),
        ]);
    }

    /**
     * Get codec information for channel
     */
    public function getChannelCodec(Request $request)
    {
        $validated = $request->validate([
            'channel' => 'required|string|max:100',
        ]);

        $codec = $this->asteriskStatus->getChannelCodecInfo($validated['channel']);

        return response()->json([
            'success' => true,
            'codec' => $codec,
        ]);
    }

    /**
     * Get RTP statistics for channel
     */
    public function getRTPStats(Request $request)
    {
        $validated = $request->validate([
            'channel' => 'required|string|max:100',
        ]);

        $stats = $this->asteriskStatus->getRTPStats($validated['channel']);

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Get trunk status
     */
    public function getTrunkStatus(Request $request)
    {
        $validated = $request->validate([
            'trunk' => 'required|string|max:50',
        ]);

        $status = $this->asteriskStatus->getTrunkStatus($validated['trunk']);

        return response()->json([
            'success' => true,
            'trunk' => $status,
        ]);
    }

    /**
     * Get complete status overview
     */
    public function getCompleteStatus()
    {
        $endpoints = $this->asteriskStatus->getAllRegisteredEndpoints();

        $summary = [
            'total_endpoints' => count($endpoints),
            'registered' => count(array_filter($endpoints, fn($e) => $e['registered'])),
            'offline' => count(array_filter($endpoints, fn($e) => !$e['registered'])),
        ];

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'endpoints' => $endpoints,
        ]);
    }

    /**
     * Get all PJSIP transports
     */
    public function getTransports()
    {
        $transports = $this->asterisk->getTransports();

        return response()->json([
            'success' => true,
            'transports' => $transports,
            'count' => count($transports),
        ]);
    }

    /**
     * Reload PJSIP configuration
     */
    public function reloadPjsip()
    {
        $result = $this->asterisk->reload();

        return response()->json([
            'success' => $result,
            'message' => $result ? 'PJSIP configuration reloaded successfully' : 'Failed to reload PJSIP',
        ]);
    }

    /**
     * Restart Asterisk service
     */
    public function restartAsterisk()
    {
        try {
            $output = shell_exec("sudo systemctl restart asterisk 2>&1");
            
            return response()->json([
                'success' => true,
                'message' => 'Asterisk service restarted successfully',
                'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restart Asterisk: ' . $e->getMessage(),
            ], 500);
        }
    }
}
