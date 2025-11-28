<?php

namespace App\Services;

use Exception;

class SystemctlService
{
    /**
     * Unified HTTP client for making outbound requests (e.g., AI solutions)
     */
    private HttpClientService $httpClient;

    /**
     * Create a new SystemctlService instance
     *
     * @param  HttpClientService|null  $httpClient  Optional HTTP client for dependency injection/testing
     */
    public function __construct(?HttpClientService $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClientService;
    }

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
            $status['pid'] = (int) $matches[1];
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
    public function getLogs(string $service, int $lines = 50, ?string $priority = null): array
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
            $coreShow = $this->execAsteriskCLI('core show version');
            if (preg_match('/Asterisk\s+([\d.]+)/', $coreShow, $matches)) {
                $status['version'] = $matches[1];
            }

            // Get active calls
            $calls = $this->execAsteriskCLI('core show calls');
            if (preg_match('/(\d+)\s+active call/', $calls, $matches)) {
                $status['active_calls'] = (int) $matches[1];
            } else {
                $status['active_calls'] = 0;
            }

            // Get channels
            $channels = $this->execAsteriskCLI('core show channels');
            if (preg_match('/(\d+)\s+active channel/', $channels, $matches)) {
                $status['active_channels'] = (int) $matches[1];
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

            return ! $this->isRunning($service);
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
            $this->execAsteriskCLI('core reload');

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
        $output = [];
        $returnCode = 0;

        // Use escapeshellarg to properly escape the command argument
        // This prevents issues where escapeshellcmd would escape characters
        // inside the Asterisk CLI command
        $escapedCommand = 'asterisk -rx '.escapeshellarg($command);
        exec($escapedCommand.' 2>&1', $output, $returnCode);

        if ($returnCode !== 0 && $returnCode !== 3) { // 3 = inactive service
            $outputStr = implode("\n", $output);
            $errorDetails = $this->getErrorDetails($returnCode, $outputStr);
            throw new Exception("Command failed with code {$returnCode}: asterisk -rx \"{$command}\"\n{$errorDetails}");
        }

        return implode("\n", $output);
    }

    /**
     * Execute shell command
     */
    private function executeCommand(string $command): string
    {
        $escapedCommand = escapeshellcmd($command);

        $output = [];
        $returnCode = 0;

        exec($escapedCommand.' 2>&1', $output, $returnCode);

        if ($returnCode !== 0 && $returnCode !== 3) { // 3 = inactive service
            $outputStr = implode("\n", $output);
            $errorDetails = $this->getErrorDetails($returnCode, $outputStr);
            throw new Exception("Command failed with code {$returnCode}: {$command}\n{$errorDetails}");
        }

        return implode("\n", $output);
    }

    /**
     * Get verbose error details based on exit code and output
     */
    private function getErrorDetails(int $exitCode, string $output): string
    {
        $details = [];

        // Add output if available
        if (! empty(trim($output))) {
            $details[] = 'Output: '.$output;
        }

        // Common exit code explanations
        $exitCodeHelp = match ($exitCode) {
            1 => 'General error - The command may not exist, Asterisk may not be running, or permission was denied.',
            2 => 'Misuse of shell command - Invalid command syntax.',
            126 => 'Permission denied - Cannot execute the command. Try running with sudo.',
            127 => "Command not found - The 'asterisk' binary may not be in PATH.",
            130 => 'Script terminated by Ctrl+C.',
            default => null,
        };

        if ($exitCodeHelp) {
            $details[] = 'Possible cause: '.$exitCodeHelp;
        }

        // Get AI-powered solution from pollinations.ai
        $aiSolution = $this->getAISolution($exitCode, $output);
        if ($aiSolution) {
            $details[] = "\nAI-Suggested Solution:\n".$aiSolution;
        }

        // Add troubleshooting tips
        $details[] = "\nTroubleshooting:";
        $details[] = '  - Check if Asterisk is running: systemctl status asterisk';
        $details[] = '  - Verify user has permission to run Asterisk CLI commands';
        $details[] = '  - Check Asterisk logs: /var/log/asterisk/full';

        return implode("\n", $details);
    }

    /**
     * Get AI-powered solution from pollinations.ai
     */
    private function getAISolution(int $exitCode, string $output): ?string
    {
        try {
            $query = "Brief fix for Asterisk CLI exit code {$exitCode}";
            if (! empty(trim($output))) {
                // Limit output length for the query
                $truncatedOutput = substr(trim($output), 0, 100);
                $query .= " with output: {$truncatedOutput}";
            }

            $url = 'https://text.pollinations.ai/'.rawurlencode($query);

            $response = $this->httpClient->get($url, [], ['timeout' => 5]);

            if ($response->successful() && ! empty(trim($response->body()))) {
                // Limit response length and format it
                $lines = explode("\n", trim($response->body()));
                $limitedLines = array_slice($lines, 0, 5);

                return '  '.implode("\n  ", $limitedLines);
            }
        } catch (\Throwable $e) {
            // Silently fail - AI solution is optional
        }

        return null;
    }
}
