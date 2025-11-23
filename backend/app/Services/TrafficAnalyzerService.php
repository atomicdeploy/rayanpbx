<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class TrafficAnalyzerService
{
    private string $interface = 'any';
    private int $snapshotLength = 65535;
    private string $captureFile = '/tmp/rayanpbx-capture.pcap';
    private ?int $capturePid = null;

    /**
     * Start capturing SIP traffic on port 5060
     */
    public function startCapture(array $options = []): array
    {
        try {
            // Check if tcpdump is installed
            if (!$this->isTcpdumpInstalled()) {
                return [
                    'success' => false,
                    'error' => 'tcpdump is not installed. Please install it: sudo apt install tcpdump',
                ];
            }

            // Check if already running
            if ($this->isCapturing()) {
                return [
                    'success' => false,
                    'error' => 'Capture is already running',
                    'pid' => $this->capturePid,
                ];
            }

            $port = $options['port'] ?? 5060;
            $rtpPort = $options['rtp_port'] ?? '10000-20000';
            $interface = $options['interface'] ?? $this->interface;
            $duration = $options['duration'] ?? null;
            
            // Build tcpdump command
            $filter = "udp port {$port} or udp portrange {$rtpPort}";
            $cmd = sprintf(
                'sudo tcpdump -i %s -s %d -w %s "%s" > /dev/null 2>&1 & echo $!',
                escapeshellarg($interface),
                $this->snapshotLength,
                escapeshellarg($this->captureFile),
                $filter
            );

            // Execute in background
            $output = shell_exec($cmd);
            $pid = trim($output);

            if ($pid && is_numeric($pid)) {
                $this->capturePid = (int)$pid;
                
                // Store PID for later reference
                file_put_contents('/tmp/rayanpbx-capture.pid', $pid);
                
                return [
                    'success' => true,
                    'pid' => $this->capturePid,
                    'file' => $this->captureFile,
                    'message' => 'Capture started successfully',
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to start capture',
            ];

        } catch (\Exception $e) {
            Log::error('Traffic capture error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Stop capturing traffic
     */
    public function stopCapture(): array
    {
        try {
            $pidFile = '/tmp/rayanpbx-capture.pid';
            
            if (file_exists($pidFile)) {
                $pid = trim(file_get_contents($pidFile));
                
                if ($pid && is_numeric($pid)) {
                    // Send SIGTERM to tcpdump
                    posix_kill((int)$pid, SIGTERM);
                    
                    // Wait a bit for graceful shutdown
                    usleep(500000); // 0.5 seconds
                    
                    // Check if still running, force kill if needed
                    if (posix_getpgid((int)$pid) !== false) {
                        posix_kill((int)$pid, SIGKILL);
                    }
                    
                    unlink($pidFile);
                    $this->capturePid = null;
                    
                    return [
                        'success' => true,
                        'message' => 'Capture stopped successfully',
                        'file' => $this->captureFile,
                    ];
                }
            }

            return [
                'success' => false,
                'error' => 'No capture process found',
            ];

        } catch (\Exception $e) {
            Log::error('Stop capture error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if capture is currently running
     */
    public function isCapturing(): bool
    {
        $pidFile = '/tmp/rayanpbx-capture.pid';
        
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            
            if ($pid && is_numeric($pid)) {
                // Check if process is still running
                return posix_getpgid((int)$pid) !== false;
            }
        }
        
        return false;
    }

    /**
     * Get capture status
     */
    public function getStatus(): array
    {
        $isRunning = $this->isCapturing();
        $pidFile = '/tmp/rayanpbx-capture.pid';
        $pid = null;
        
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
        }

        $fileSize = 0;
        $packets = 0;
        
        if (file_exists($this->captureFile)) {
            $fileSize = filesize($this->captureFile);
            
            // Count packets using tcpdump if file exists
            if ($fileSize > 0) {
                $output = shell_exec(sprintf(
                    'tcpdump -r %s 2>/dev/null | wc -l',
                    escapeshellarg($this->captureFile)
                ));
                $packets = (int)trim($output);
            }
        }

        return [
            'running' => $isRunning,
            'pid' => $pid,
            'file' => $this->captureFile,
            'file_size' => $fileSize,
            'packets_captured' => $packets,
            'formatted_size' => $this->formatBytes($fileSize),
        ];
    }

    /**
     * Analyze captured packets
     */
    public function analyze(): array
    {
        try {
            if (!file_exists($this->captureFile)) {
                return [
                    'success' => false,
                    'error' => 'No capture file found. Start a capture first.',
                ];
            }

            // Parse SIP messages
            $sipMessages = $this->parseSIPMessages();
            
            // Parse RTP streams
            $rtpStreams = $this->parseRTPStreams();
            
            // Get statistics
            $stats = $this->getStatistics();

            return [
                'success' => true,
                'sip_messages' => $sipMessages,
                'rtp_streams' => $rtpStreams,
                'statistics' => $stats,
            ];

        } catch (\Exception $e) {
            Log::error('Traffic analysis error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse SIP messages from capture
     */
    private function parseSIPMessages(): array
    {
        $cmd = sprintf(
            'tcpdump -r %s -A "port 5060" 2>/dev/null | grep -E "^(INVITE|REGISTER|BYE|ACK|OPTIONS|CANCEL|TRYING|RINGING|OK)" | head -50',
            escapeshellarg($this->captureFile)
        );
        
        $output = shell_exec($cmd);
        $lines = $output ? explode("\n", trim($output)) : [];
        
        $messages = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\w+)\s+(.+)/', $line, $matches)) {
                $messages[] = [
                    'method' => $matches[1],
                    'details' => $matches[2] ?? '',
                    'timestamp' => time(),
                ];
            }
        }
        
        return $messages;
    }

    /**
     * Parse RTP streams
     */
    private function parseRTPStreams(): array
    {
        // This is a simplified version - full RTP analysis requires more complex parsing
        $cmd = sprintf(
            'tcpdump -r %s "udp portrange 10000-20000" 2>/dev/null | wc -l',
            escapeshellarg($this->captureFile)
        );
        
        $count = (int)trim(shell_exec($cmd));
        
        return [
            'total_packets' => $count,
            'estimated_streams' => $count > 0 ? ceil($count / 100) : 0,
        ];
    }

    /**
     * Get traffic statistics
     */
    private function getStatistics(): array
    {
        // Get total packets
        $totalCmd = sprintf(
            'tcpdump -r %s 2>/dev/null | wc -l',
            escapeshellarg($this->captureFile)
        );
        $totalPackets = (int)trim(shell_exec($totalCmd));

        // Get SIP packets
        $sipCmd = sprintf(
            'tcpdump -r %s "port 5060" 2>/dev/null | wc -l',
            escapeshellarg($this->captureFile)
        );
        $sipPackets = (int)trim(shell_exec($sipCmd));

        // Get RTP packets
        $rtpCmd = sprintf(
            'tcpdump -r %s "udp portrange 10000-20000" 2>/dev/null | wc -l',
            escapeshellarg($this->captureFile)
        );
        $rtpPackets = (int)trim(shell_exec($rtpCmd));

        return [
            'total_packets' => $totalPackets,
            'sip_packets' => $sipPackets,
            'rtp_packets' => $rtpPackets,
            'file_size' => file_exists($this->captureFile) ? filesize($this->captureFile) : 0,
        ];
    }

    /**
     * Clear capture file
     */
    public function clearCapture(): array
    {
        try {
            if ($this->isCapturing()) {
                return [
                    'success' => false,
                    'error' => 'Stop capture before clearing',
                ];
            }

            if (file_exists($this->captureFile)) {
                unlink($this->captureFile);
            }

            return [
                'success' => true,
                'message' => 'Capture file cleared',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if tcpdump is installed
     */
    private function isTcpdumpInstalled(): bool
    {
        $result = shell_exec('which tcpdump 2>/dev/null');
        return !empty(trim($result));
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        
        return round($bytes, 2) . ' ' . $units[$index];
    }
}
