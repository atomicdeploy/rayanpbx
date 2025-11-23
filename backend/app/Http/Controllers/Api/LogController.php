<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Get recent log entries
     */
    public function index(Request $request)
    {
        $lines = $request->input('lines', 100);
        $level = $request->input('level', 'all');
        
        $logFile = config('rayanpbx.asterisk.config_path', '/var/log/asterisk') . '/messages';
        
        try {
            $logs = $this->readLogFile($logFile, $lines, $level);
            
            return response()->json(['logs' => $logs]);
        } catch (\Exception $e) {
            return response()->json([
                'logs' => [],
                'error' => 'Unable to read log file'
            ], 500);
        }
    }
    
    /**
     * Stream logs (SSE)
     */
    public function stream(Request $request)
    {
        return response()->stream(function () {
            $logFile = config('rayanpbx.asterisk.config_path', '/var/log/asterisk') . '/messages';
            
            // Send headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            
            $lastSize = 0;
            
            while (true) {
                if (connection_aborted()) {
                    break;
                }
                
                try {
                    if (file_exists($logFile)) {
                        clearstatcache(true, $logFile);
                        $currentSize = filesize($logFile);
                        
                        if ($currentSize > $lastSize) {
                            $fp = fopen($logFile, 'r');
                            fseek($fp, $lastSize);
                            
                            while (($line = fgets($fp)) !== false) {
                                $data = json_encode([
                                    'timestamp' => time(),
                                    'message' => trim($line)
                                ]);
                                
                                echo "data: {$data}\n\n";
                                flush();
                            }
                            
                            $lastSize = $currentSize;
                            fclose($fp);
                        }
                    }
                } catch (\Exception $e) {
                    // Continue on error
                }
                
                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
    
    /**
     * Read log file
     */
    private function readLogFile(string $file, int $lines, string $level)
    {
        if (!file_exists($file)) {
            return [];
        }
        
        $command = "tail -n {$lines} " . escapeshellarg($file);
        $output = shell_exec($command);
        
        if (!$output) {
            return [];
        }
        
        $logs = [];
        $logLines = explode("\n", trim($output));
        
        foreach ($logLines as $line) {
            if (empty($line)) {
                continue;
            }
            
            $parsed = $this->parseLogLine($line);
            
            if ($level !== 'all' && isset($parsed['level']) && $parsed['level'] !== $level) {
                continue;
            }
            
            $logs[] = $parsed;
        }
        
        return $logs;
    }
    
    /**
     * Parse a log line
     */
    private function parseLogLine(string $line)
    {
        // Simple parser for Asterisk log format
        // Example: [2024-01-01 12:00:00] NOTICE[1234] chan_pjsip.c: Message
        
        if (preg_match('/\[(.*?)\]\s+(\w+)\[(.*?)\]\s+(.*?):\s+(.*)/', $line, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => strtolower($matches[2]),
                'process' => $matches[3],
                'source' => $matches[4],
                'message' => $matches[5],
                'raw' => $line,
            ];
        }
        
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'info',
            'process' => '',
            'source' => '',
            'message' => $line,
            'raw' => $line,
        ];
    }
}
