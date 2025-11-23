<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrafficAnalyzerService;
use Illuminate\Http\Request;

class TrafficController extends Controller
{
    private TrafficAnalyzerService $trafficService;

    public function __construct(TrafficAnalyzerService $trafficService)
    {
        $this->trafficService = $trafficService;
    }

    /**
     * Start packet capture
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'port' => 'nullable|integer|min:1|max:65535',
            'rtp_port' => 'nullable|string',
            'interface' => 'nullable|string|max:20',
            'duration' => 'nullable|integer|min:1|max:3600',
        ]);

        $result = $this->trafficService->startCapture($validated);

        return response()->json($result);
    }

    /**
     * Stop packet capture
     */
    public function stop()
    {
        $result = $this->trafficService->stopCapture();

        return response()->json($result);
    }

    /**
     * Get capture status
     */
    public function status()
    {
        $status = $this->trafficService->getStatus();

        return response()->json([
            'success' => true,
            'status' => $status,
        ]);
    }

    /**
     * Analyze captured traffic
     */
    public function analyze()
    {
        $result = $this->trafficService->analyze();

        return response()->json($result);
    }

    /**
     * Clear capture file
     */
    public function clear()
    {
        $result = $this->trafficService->clearCapture();

        return response()->json($result);
    }
}
