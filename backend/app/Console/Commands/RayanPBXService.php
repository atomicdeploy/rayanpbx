<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemctlService;
use Exception;

class RayanPBXService extends Command
{
    protected SystemctlService $systemctl;

    public function __construct(SystemctlService $systemctl)
    {
        parent::__construct();
        $this->systemctl = $systemctl;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:service {action} {service?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage RayanPBX system services (start|stop|restart|reload|status)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $service = $this->argument('service');

        $validActions = ['start', 'stop', 'restart', 'reload', 'status'];
        $validServices = ['asterisk', 'rayanpbx-api', 'mysql', 'redis', 'all'];

        if (!in_array($action, $validActions)) {
            $this->error("Invalid action: {$action}");
            $this->info("Valid actions: " . implode(', ', $validActions));
            return 1;
        }

        if (!$service) {
            $service = $this->choice('Which service?', $validServices, 0);
        }

        if (!in_array($service, $validServices)) {
            $this->error("Invalid service: {$service}");
            $this->info("Valid services: " . implode(', ', $validServices));
            return 1;
        }

        if ($service === 'all') {
            return $this->handleAllServices($action);
        }

        // Map service names
        $serviceMap = [
            'asterisk' => 'asterisk',
            'rayanpbx-api' => 'rayanpbx-api',
            'mysql' => 'mysql',
            'redis' => 'redis-server',
        ];

        $actualService = $serviceMap[$service];

        try {
            switch ($action) {
                case 'start':
                    $this->info("Starting {$service}...");
                    if ($this->systemctl->start($actualService)) {
                        $this->info("✓ {$service} started successfully");
                        return 0;
                    } else {
                        $this->error("✗ Failed to start {$service}");
                        return 1;
                    }

                case 'stop':
                    $this->info("Stopping {$service}...");
                    if ($this->systemctl->stop($actualService)) {
                        $this->info("✓ {$service} stopped successfully");
                        return 0;
                    } else {
                        $this->error("✗ Failed to stop {$service}");
                        return 1;
                    }

                case 'restart':
                    $this->info("Restarting {$service}...");
                    if ($this->systemctl->restart($actualService)) {
                        $this->info("✓ {$service} restarted successfully");
                        return 0;
                    } else {
                        $this->error("✗ Failed to restart {$service}");
                        return 1;
                    }

                case 'reload':
                    if ($service === 'asterisk') {
                        $this->info("Reloading Asterisk configuration...");
                        if ($this->systemctl->reloadAsterisk()) {
                            $this->info("✓ Asterisk configuration reloaded successfully");
                            return 0;
                        } else {
                            $this->error("✗ Failed to reload Asterisk configuration");
                            return 1;
                        }
                    } else {
                        $this->info("Reloading {$service}...");
                        if ($this->systemctl->reload($actualService)) {
                            $this->info("✓ {$service} reloaded successfully");
                            return 0;
                        } else {
                            $this->error("✗ Failed to reload {$service}");
                            return 1;
                        }
                    }

                case 'status':
                    $status = $this->systemctl->getStatus($actualService);
                    $this->info("Status for {$service}:");
                    $this->table(
                        ['Property', 'Value'],
                        [
                            ['Active', $status['active'] ? 'Yes' : 'No'],
                            ['Enabled', $status['enabled'] ? 'Yes' : 'No'],
                            ['Loaded', $status['loaded'] ? 'Yes' : 'No'],
                            ['PID', $status['pid'] ?? 'N/A'],
                            ['Memory', $status['memory'] ?? 'N/A'],
                            ['Uptime', isset($status['uptime']) && $status['uptime'] ? $this->formatUptime($status['uptime']) : 'N/A'],
                        ]
                    );
                    return 0;
            }
        } catch (Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Handle action for all services
     */
    private function handleAllServices(string $action): int
    {
        $services = ['mysql', 'redis-server', 'asterisk', 'rayanpbx-api'];
        $serviceNames = ['MySQL', 'Redis', 'Asterisk', 'RayanPBX API'];
        $failed = false;

        if (!in_array($action, ['start', 'stop', 'restart', 'status'])) {
            $this->error("Action '{$action}' is not supported for all services");
            return 1;
        }

        foreach ($services as $index => $service) {
            try {
                $name = $serviceNames[$index];
                
                switch ($action) {
                    case 'start':
                        $this->info("Starting {$name}...");
                        if (!$this->systemctl->start($service)) {
                            $this->error("✗ Failed to start {$name}");
                            $failed = true;
                        } else {
                            $this->info("✓ {$name} started");
                        }
                        break;

                    case 'stop':
                        $this->info("Stopping {$name}...");
                        if (!$this->systemctl->stop($service)) {
                            $this->error("✗ Failed to stop {$name}");
                            $failed = true;
                        } else {
                            $this->info("✓ {$name} stopped");
                        }
                        break;

                    case 'restart':
                        $this->info("Restarting {$name}...");
                        if (!$this->systemctl->restart($service)) {
                            $this->error("✗ Failed to restart {$name}");
                            $failed = true;
                        } else {
                            $this->info("✓ {$name} restarted");
                        }
                        break;

                    case 'status':
                        $status = $this->systemctl->getStatus($service);
                        $statusText = $status['active'] ? '<info>✓ Running</info>' : '<error>✗ Stopped</error>';
                        $this->line("{$name}: {$statusText}");
                        break;
                }
            } catch (Exception $e) {
                $this->error("Error with {$serviceNames[$index]}: " . $e->getMessage());
                $failed = true;
            }
        }

        return $failed ? 1 : 0;
    }

    /**
     * Format uptime in a human-readable format
     */
    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }

        return implode(' ', $parts) ?: '< 1m';
    }
}
