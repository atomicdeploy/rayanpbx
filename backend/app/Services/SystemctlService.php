<?php

namespace App\Services;

use Exception;

class SystemctlService
{
    /**
     * Check if a service is running
     */
    public function isRunning(string $service): bool
    {
        $output = $this->executeCommand("systemctl is-active {$service}");
        return trim($output) === 'active';
    }

    /**
     * Get service status
     */
    public function getStatus(string $service): array
    {
        $status = [
            'active' => false,
            'enabled' => false,
            'loaded' => false,
            'pid' => null,
            'uptime' => null,
            'memory' => null,
        ];

        // Check active status
        $activeOutput = $this->executeCommand("systemctl is-active {$service}");
        $status['active'] = trim($activeOutput) === 'active';

        // Check enabled status
        $enabledOutput = $this->executeCommand("systemctl is-enabled {$service}");
        $status['enabled'] = trim($enabledOutput) === 'enabled';

        // Get detailed status
        $detailOutput = $this->executeCommand("systemctl status {$service}");
        
        // Parse PID
        if (preg_match('/Main PID:\s+(\d+)/', $detailOutput, $matches)) {
            $status['pid'] = (int)$matches[1];
        }

        // Parse memory usage
        if (preg_match('/Memory:\s+([\d.]+[KMG])/', $detailOutput, $matches)) {
            $status['memory'] = $matches[1];
        }

        // Get uptime
        $uptime = $this->executeCommand("systemctl show {$service} --property=ActiveEnterTimestamp");
        if (preg_match('/ActiveEnterTimestamp=(.+)/', $uptime, $matches)) {
            $timestamp = strtotime(trim($matches[1]));
            if ($timestamp) {
                $status['uptime'] = time() - $timestamp;
            }
        }

        $status['loaded'] = str_contains($detailOutput, 'Loaded: loaded');

        return $status;
    }

    /**
     * Get service logs
     */
    public function getLogs(string $service, int $lines = 50, string $priority = null): array
    {
        $command = "journalctl -u {$service} -n {$lines} --no-pager";
        
        if ($priority) {
            $command .= " -p {$priority}";
        }

        $output = $this->executeCommand($command);
        $logs = [];

        foreach (explode("\n", $output) as $line) {
            if (empty(trim($line))) {
                continue;
            }

            // Parse journalctl output
            if (preg_match('/^(\w+\s+\d+\s+[\d:]+)\s+(\S+)\s+(\S+)\[(\d+)\]:\s+(.+)$/', $line, $matches)) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'host' => $matches[2],
                    'process' => $matches[3],
                    'pid' => $matches[4],
                    'message' => $matches[5],
                    'raw' => $line,
                ];
            } else {
                $logs[] = [
                    'timestamp' => '',
                    'host' => '',
                    'process' => '',
                    'pid' => '',
                    'message' => $line,
                    'raw' => $line,
                ];
            }
        }

        return $logs;
    }

    /**
     * Get Asterisk-specific status
     */
    public function getAsteriskStatus(): array
    {
        $status = $this->getStatus('asterisk');
        
        // Get Asterisk CLI output
        try {
            $coreShow = $this->executeCommand('asterisk -rx "core show version"');
            if (preg_match('/Asterisk\s+([\d.]+)/', $coreShow, $matches)) {
                $status['version'] = $matches[1];
            }

            // Get active calls
            $calls = $this->executeCommand('asterisk -rx "core show calls"');
            if (preg_match('/(\d+)\s+active call/', $calls, $matches)) {
                $status['active_calls'] = (int)$matches[1];
            } else {
                $status['active_calls'] = 0;
            }

            // Get channels
            $channels = $this->executeCommand('asterisk -rx "core show channels"');
            if (preg_match('/(\d+)\s+active channel/', $channels, $matches)) {
                $status['active_channels'] = (int)$matches[1];
            } else {
                $status['active_channels'] = 0;
            }

        } catch (Exception $e) {
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    /**
     * Restart a service
     */
    public function restart(string $service): bool
    {
        try {
            $this->executeCommand("systemctl restart {$service}");
            sleep(2); // Wait for service to restart
            return $this->isRunning($service);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Reload a service
     */
    public function reload(string $service): bool
    {
        try {
            $this->executeCommand("systemctl reload {$service}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Start a service
     */
    public function start(string $service): bool
    {
        try {
            $this->executeCommand("systemctl start {$service}");
            sleep(1);
            return $this->isRunning($service);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Stop a service
     */
    public function stop(string $service): bool
    {
        try {
            $this->executeCommand("systemctl stop {$service}");
            sleep(1);
            return !$this->isRunning($service);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Reload Asterisk configuration
     */
    public function reloadAsterisk(): bool
    {
        try {
            $this->executeCommand('asterisk -rx "core reload"');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Execute Asterisk CLI command
     */
    public function execAsteriskCLI(string $command): string
    {
        return $this->executeCommand("asterisk -rx \"{$command}\"");
    }

    /**
     * Execute shell command
     */
    private function executeCommand(string $command): string
    {
        $escapedCommand = escapeshellcmd($command);
        
        $output = [];
        $returnCode = 0;
        
        exec($escapedCommand, $output, $returnCode);
        
        if ($returnCode !== 0 && $returnCode !== 3) { // 3 = inactive service
            throw new Exception("Command failed with code {$returnCode}: {$command}");
        }
        
        return implode("\n", $output);
    }
}
