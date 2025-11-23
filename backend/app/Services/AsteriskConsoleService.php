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
}
