<?php

namespace App\Services;

use Exception;

class AsteriskConsoleService
{
    private SystemctlService $systemctl;
    
    public function __construct(SystemctlService $systemctl)
    {
        $this->systemctl = $systemctl;
    }

    /**
     * Execute Asterisk CLI command
     */
    public function executeCommand(string $command): array
    {
        try {
            $output = $this->systemctl->execAsteriskCLI($command);
            
            return [
                'success' => true,
                'command' => $command,
                'output' => $output,
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'command' => $command,
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get Asterisk version
     */
    public function getVersion(): string
    {
        $result = $this->executeCommand('core show version');
        if ($result['success']) {
            // Parse version from output
            if (preg_match('/Asterisk\s+([\d.]+)/', $result['output'], $matches)) {
                return $matches[1];
            }
        }
        return 'Unknown';
    }

    /**
     * Get active calls
     */
    public function getActiveCalls(): array
    {
        $result = $this->executeCommand('core show calls');
        
        if (!$result['success']) {
            return [];
        }

        $calls = [];
        $lines = explode("\n", $result['output']);
        
        foreach ($lines as $line) {
            if (preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(\d+:\d+:\d+)/', $line, $matches)) {
                $calls[] = [
                    'channel' => $matches[1],
                    'location' => $matches[2],
                    'state' => $matches[3],
                    'duration' => $matches[4],
                ];
            }
        }

        return $calls;
    }

    /**
     * Get channel status
     */
    public function getChannels(): array
    {
        $result = $this->executeCommand('core show channels');
        
        if (!$result['success']) {
            return [];
        }

        $channels = [];
        $lines = explode("\n", $result['output']);
        
        foreach ($lines as $line) {
            if (preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $channels[] = [
                    'channel' => $matches[1],
                    'context' => $matches[2],
                    'extension' => $matches[3],
                    'priority' => $matches[4],
                ];
            }
        }

        return $channels;
    }

    /**
     * Get PJSIP endpoints
     */
    public function getPjsipEndpoints(): array
    {
        $result = $this->executeCommand('pjsip show endpoints');
        
        if (!$result['success']) {
            return [];
        }

        $endpoints = [];
        $lines = explode("\n", $result['output']);
        
        foreach ($lines as $line) {
            // Parse PJSIP endpoint output
            if (preg_match('/^\s*(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $endpoints[] = [
                    'endpoint' => $matches[1],
                    'state' => $matches[2],
                    'contacts' => $matches[3],
                    'transport' => $matches[4],
                    'identify' => $matches[5],
                ];
            }
        }

        return $endpoints;
    }

    /**
     * Get PJSIP registrations
     */
    public function getPjsipRegistrations(): array
    {
        $result = $this->executeCommand('pjsip show registrations');
        
        if (!$result['success']) {
            return [];
        }

        $registrations = [];
        $lines = explode("\n", $result['output']);
        
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\S+)\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $registrations[] = [
                    'registration' => $matches[1],
                    'state' => $matches[2],
                    'server_uri' => $matches[3],
                ];
            }
        }

        return $registrations;
    }

    /**
     * Reload Asterisk modules
     */
    public function reload(string $module = null): array
    {
        if ($module) {
            return $this->executeCommand("module reload {$module}");
        }
        return $this->executeCommand('core reload');
    }

    /**
     * Restart Asterisk
     */
    public function restart(): array
    {
        return $this->executeCommand('core restart now');
    }

    /**
     * Soft hangup channel
     */
    public function hangupChannel(string $channel): array
    {
        return $this->executeCommand("channel request hangup {$channel}");
    }

    /**
     * Originate call
     */
    public function originateCall(string $channel, string $extension, string $context = 'from-internal'): array
    {
        $command = "channel originate {$channel} extension {$extension}@{$context}";
        return $this->executeCommand($command);
    }

    /**
     * Show dialplan
     */
    public function showDialplan(string $context = null): array
    {
        if ($context) {
            return $this->executeCommand("dialplan show {$context}");
        }
        return $this->executeCommand('dialplan show');
    }

    /**
     * Get SIP peers (for compatibility)
     */
    public function getSipPeers(): array
    {
        // Try PJSIP first
        $pjsipResult = $this->getPjsipEndpoints();
        if (!empty($pjsipResult)) {
            return $pjsipResult;
        }

        // Fallback to chan_sip
        $result = $this->executeCommand('sip show peers');
        
        if (!$result['success']) {
            return [];
        }

        $peers = [];
        $lines = explode("\n", $result['output']);
        
        foreach ($lines as $line) {
            if (preg_match('/^(\S+)\s+(\S+)\s+(\w+)\s+(\w+)/', $line, $matches)) {
                $peers[] = [
                    'peer' => $matches[1],
                    'host' => $matches[2],
                    'dynamic' => $matches[3],
                    'status' => $matches[4],
                ];
            }
        }

        return $peers;
    }

    /**
     * Get console output (last N lines from logs)
     */
    public function getConsoleOutput(int $lines = 50): array
    {
        $logFile = config('rayanpbx.asterisk.config_path', '/var/log/asterisk') . '/messages';
        
        if (!file_exists($logFile)) {
            return [
                'success' => false,
                'error' => 'Log file not found',
            ];
        }

        try {
            $command = "tail -n {$lines} " . escapeshellarg($logFile);
            $output = shell_exec($command);
            
            $logs = [];
            $logLines = explode("\n", trim($output));
            
            foreach ($logLines as $line) {
                if (empty($line)) continue;
                
                // Parse Asterisk log format
                if (preg_match('/\[(.*?)\]\s+(\w+)\[(.*?)\]\s+(.*?):\s+(.*)/', $line, $matches)) {
                    $logs[] = [
                        'timestamp' => $matches[1],
                        'level' => strtolower($matches[2]),
                        'process' => $matches[3],
                        'source' => $matches[4],
                        'message' => $matches[5],
                    ];
                } else {
                    $logs[] = [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'level' => 'info',
                        'process' => '',
                        'source' => '',
                        'message' => $line,
                    ];
                }
            }
            
            return [
                'success' => true,
                'logs' => $logs,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Start console session (for WebSocket streaming)
     */
    public function startConsoleSession(): array
    {
        try {
            // Check if asterisk is running
            if (!$this->systemctl->isRunning('asterisk')) {
                return [
                    'success' => false,
                    'error' => 'Asterisk is not running',
                ];
            }

            return [
                'success' => true,
                'message' => 'Console session ready',
                'commands' => $this->getAvailableCommands(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get list of available commands
     */
    public function getAvailableCommands(): array
    {
        $result = $this->executeCommand('core show help');
        
        if (!$result['success']) {
            return [];
        }

        $commands = [];
        $lines = explode("\n", $result['output']);
        
        foreach ($lines as $line) {
            if (preg_match('/^\s+(\S.+?)\s{2,}(.+)$/', $line, $matches)) {
                $commands[] = [
                    'command' => trim($matches[1]),
                    'description' => trim($matches[2]),
                ];
            }
        }

        return $commands;
    }

    /**
     * Validate command (basic security check)
     */
    public function validateCommand(string $command): bool
    {
        // Blacklist dangerous commands
        $blacklist = [
            'core stop',
            'core restart',
            'core shutdown',
            'module unload',
            'database',
            'shell',
            'system',
        ];

        foreach ($blacklist as $dangerous) {
            if (str_starts_with(strtolower($command), $dangerous)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute safe command (with validation)
     */
    public function executeSafeCommand(string $command): array
    {
        if (!$this->validateCommand($command)) {
            return [
                'success' => false,
                'command' => $command,
                'error' => 'Command not allowed for security reasons',
            ];
        }

        return $this->executeCommand($command);
    }

    /**
     * Get the path to Asterisk full log for streaming
     */
    public function getFullLogPath(): string
    {
        // Check common Asterisk log file locations
        $possiblePaths = [
            '/var/log/asterisk/full',
            '/var/log/asterisk/messages',
            config('rayanpbx.asterisk.log_path', '/var/log/asterisk') . '/full',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        // Default to full log
        return '/var/log/asterisk/full';
    }

    /**
     * Stream live Asterisk console output (Server-Sent Events)
     * This provides similar output to `asterisk -rvvvvvvvvv`
     * 
     * @param callable $callback Function to call with each log line
     * @param int $verbosity Verbosity level (1-10, default 5)
     * @return void
     */
    public function streamLiveOutput(callable $callback, int $verbosity = 5): void
    {
        $logFile = $this->getFullLogPath();
        
        if (!file_exists($logFile)) {
            $callback([
                'type' => 'error',
                'message' => "Log file not found: {$logFile}",
                'timestamp' => now()->toIso8601String(),
            ]);
            return;
        }

        // Get initial file size to start from the end
        clearstatcache(true, $logFile);
        $lastSize = filesize($logFile);
        $lastInode = fileinode($logFile);

        // Send initial connection message
        $callback([
            'type' => 'connected',
            'message' => 'Connected to Asterisk live console',
            'verbosity' => $verbosity,
            'logFile' => $logFile,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Stream loop - this runs until connection is closed
        while (!connection_aborted()) {
            clearstatcache(true, $logFile);
            
            // Check if file was rotated (inode changed)
            $currentInode = @fileinode($logFile);
            if ($currentInode !== false && $currentInode !== $lastInode) {
                $lastSize = 0;
                $lastInode = $currentInode;
                $callback([
                    'type' => 'info',
                    'message' => 'Log file rotated, reconnecting...',
                    'timestamp' => now()->toIso8601String(),
                ]);
            }
            
            $currentSize = @filesize($logFile);
            
            if ($currentSize === false) {
                usleep(500000); // 500ms
                continue;
            }
            
            if ($currentSize > $lastSize) {
                $handle = @fopen($logFile, 'r');
                if ($handle) {
                    fseek($handle, $lastSize);
                    
                    while (($line = fgets($handle)) !== false) {
                        $line = trim($line);
                        if (empty($line)) {
                            continue;
                        }
                        
                        $parsed = $this->parseLiveLogLine($line, $verbosity);
                        if ($parsed !== null) {
                            $callback($parsed);
                        }
                    }
                    
                    $lastSize = ftell($handle);
                    fclose($handle);
                }
            }
            
            usleep(100000); // 100ms delay between checks
        }
    }

    /**
     * Parse a live log line and filter based on verbosity
     * 
     * @param string $line Raw log line
     * @param int $verbosity Verbosity level (1-10)
     * @return array|null Parsed log entry or null if filtered out
     */
    private function parseLiveLogLine(string $line, int $verbosity): ?array
    {
        // Parse Asterisk log format: [timestamp] LEVEL[process] source: message
        // Example: [2024-01-01 12:00:00] NOTICE[1234] chan_pjsip.c: Message
        if (preg_match('/\[(.*?)\]\s+(\w+)\[(.*?)\]\s+(.*?):\s*(.*)/', $line, $matches)) {
            $level = strtolower($matches[2]);
            
            // Filter based on verbosity level
            $levelPriority = $this->getLevelPriority($level);
            if ($levelPriority > $verbosity) {
                return null;
            }
            
            return [
                'type' => 'log',
                'timestamp' => $matches[1],
                'level' => $level,
                'process' => $matches[3],
                'source' => $matches[4],
                'message' => $matches[5],
                'raw' => $line,
                'isError' => in_array($level, ['error', 'warning']),
            ];
        }
        
        // For lines that don't match the standard format
        return [
            'type' => 'log',
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'level' => 'verbose',
            'process' => '',
            'source' => '',
            'message' => $line,
            'raw' => $line,
            'isError' => false,
        ];
    }

    /**
     * Get priority for log level (lower = more important)
     */
    private function getLevelPriority(string $level): int
    {
        return match (strtolower($level)) {
            'error' => 1,
            'warning' => 2,
            'notice' => 3,
            'verbose' => 5,
            'dtmf' => 6,
            'debug' => 8,
            default => 5,
        };
    }

    /**
     * Get recent Asterisk errors (registration failures, etc.)
     * 
     * @param int $lines Number of lines to search
     * @return array Array of error entries
     */
    public function getRecentErrors(int $lines = 500): array
    {
        $logFile = $this->getFullLogPath();
        
        if (!file_exists($logFile)) {
            return [];
        }

        try {
            // Use grep to find error-related lines efficiently
            $errorPatterns = [
                'log_failed_request',
                'Failed to authenticate',
                'No matching endpoint',
                'Registration .* failed',
                'SECURITY',
                'ERROR',
                'WARNING.*failed',
            ];
            
            $pattern = implode('|', $errorPatterns);
            $command = sprintf(
                "tail -n %d %s | grep -iE %s 2>/dev/null | tail -100",
                (int)$lines,
                escapeshellarg($logFile),
                escapeshellarg($pattern)
            );
            
            $output = shell_exec($command);
            
            if (empty($output)) {
                return [];
            }

            $errors = [];
            $logLines = explode("\n", trim($output));
            
            foreach ($logLines as $line) {
                if (empty($line)) {
                    continue;
                }
                
                $parsed = $this->parseLiveLogLine($line, 10);
                if ($parsed !== null) {
                    $parsed['isError'] = true;
                    $errors[] = $parsed;
                }
            }
            
            return $errors;
        } catch (Exception $e) {
            return [];
        }
    }
}
