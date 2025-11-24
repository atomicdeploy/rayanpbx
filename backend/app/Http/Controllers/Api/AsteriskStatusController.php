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
     * Get Asterisk service errors from log file
     */
    public function getErrors()
    {
        $errorLogFile = '/var/log/rayanpbx/asterisk-errors.log';
        $errors = [];
        
        if (file_exists($errorLogFile)) {
            try {
                // Read the last 50 lines of the error log
                $content = shell_exec("tail -n 100 " . escapeshellarg($errorLogFile));
                
                if ($content) {
                    // Parse the log content into structured errors
                    $entries = preg_split('/={30,}/', $content);
                    
                    foreach ($entries as $entry) {
                        $entry = trim($entry);
                        if (empty($entry)) continue;
                        
                        // Extract timestamp and context
                        if (preg_match('/Asterisk Error - (.+?)\nContext: (.+?)$/m', $entry, $matches)) {
                            $timestamp = $matches[1] ?? '';
                            $context = $matches[2] ?? '';
                            
                            // Extract error message (everything after Context line)
                            $lines = explode("\n", $entry);
                            $errorLines = [];
                            $foundContext = false;
                            
                            foreach ($lines as $line) {
                                if ($foundContext && !empty(trim($line))) {
                                    $errorLines[] = $line;
                                }
                                if (strpos($line, 'Context:') !== false) {
                                    $foundContext = true;
                                }
                            }
                            
                            $errors[] = [
                                'timestamp' => $timestamp,
                                'context' => $context,
                                'message' => implode("\n", $errorLines),
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Failed to read Asterisk error log: ' . $e->getMessage());
            }
        }
        
        // Also check systemctl status for current errors
        try {
            exec('systemctl is-active asterisk 2>&1', $output, $returnCode);
            $isActive = ($returnCode === 0);
            
            if (!$isActive) {
                // Get recent journal errors
                $journalErrors = shell_exec('journalctl -u asterisk -n 20 --no-pager 2>/dev/null | grep -i "error\|fail\|warning" | tail -5');
                
                if ($journalErrors) {
                    $errors[] = [
                        'timestamp' => date('Y-m-d H:M:S'),
                        'context' => 'Current Asterisk Status',
                        'message' => "Asterisk service is not running.\n\nRecent errors:\n" . trim($journalErrors),
                    ];
                }
            }
        } catch (\Exception $e) {
            \Log::error('Failed to check Asterisk status: ' . $e->getMessage());
        }
        
        return response()->json([
            'success' => true,
            'errors' => array_reverse($errors), // Most recent first
            'count' => count($errors),
            'has_errors' => count($errors) > 0,
        ]);

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
