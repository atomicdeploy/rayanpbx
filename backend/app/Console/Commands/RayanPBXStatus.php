<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemctlService;
use App\Services\AsteriskStatusService;
use Exception;

class RayanPBXStatus extends Command
{
    protected SystemctlService $systemctl;
    protected AsteriskStatusService $asterisk;

    public function __construct(SystemctlService $systemctl, AsteriskStatusService $asterisk)
    {
        parent::__construct();
        $this->systemctl = $systemctl;
        $this->asterisk = $asterisk;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:status {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display status of all RayanPBX services and components';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('   RayanPBX System Status');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();

        $status = [];

        // Check Asterisk service
        $this->comment('Asterisk PBX:');
        try {
            $asteriskStatus = $this->systemctl->getAsteriskStatus();
            $status['asterisk'] = $asteriskStatus;
            
            if ($asteriskStatus['active']) {
                $this->line('  Status: <info>✓ Running</info>');
                if (isset($asteriskStatus['version'])) {
                    $this->line('  Version: ' . $asteriskStatus['version']);
                }
                if (isset($asteriskStatus['active_calls'])) {
                    $this->line('  Active Calls: ' . $asteriskStatus['active_calls']);
                }
                if (isset($asteriskStatus['active_channels'])) {
                    $this->line('  Active Channels: ' . $asteriskStatus['active_channels']);
                }
                if (isset($asteriskStatus['uptime']) && $asteriskStatus['uptime']) {
                    $uptime = $this->formatUptime($asteriskStatus['uptime']);
                    $this->line('  Uptime: ' . $uptime);
                }
            } else {
                $this->line('  Status: <error>✗ Stopped</error>');
            }
        } catch (Exception $e) {
            $this->line('  Status: <error>✗ Error: ' . $e->getMessage() . '</error>');
            $status['asterisk'] = ['error' => $e->getMessage()];
        }
        $this->newLine();

        // Check RayanPBX API service
        $this->comment('RayanPBX API:');
        try {
            $apiStatus = $this->systemctl->getStatus('rayanpbx-api');
            $status['api'] = $apiStatus;
            
            if ($apiStatus['active']) {
                $this->line('  Status: <info>✓ Running</info>');
                if ($apiStatus['pid']) {
                    $this->line('  PID: ' . $apiStatus['pid']);
                }
                if ($apiStatus['memory']) {
                    $this->line('  Memory: ' . $apiStatus['memory']);
                }
                if (isset($apiStatus['uptime']) && $apiStatus['uptime']) {
                    $uptime = $this->formatUptime($apiStatus['uptime']);
                    $this->line('  Uptime: ' . $uptime);
                }
            } else {
                $this->line('  Status: <error>✗ Stopped</error>');
            }
        } catch (Exception $e) {
            $this->line('  Status: <error>✗ Error: ' . $e->getMessage() . '</error>');
            $status['api'] = ['error' => $e->getMessage()];
        }
        $this->newLine();

        // Check MySQL service
        $this->comment('MySQL Database:');
        try {
            $mysqlStatus = $this->systemctl->getStatus('mysql');
            $status['mysql'] = $mysqlStatus;
            
            if ($mysqlStatus['active']) {
                $this->line('  Status: <info>✓ Running</info>');
                if ($mysqlStatus['pid']) {
                    $this->line('  PID: ' . $mysqlStatus['pid']);
                }
            } else {
                $this->line('  Status: <error>✗ Stopped</error>');
            }
        } catch (Exception $e) {
            $this->line('  Status: <error>✗ Error: ' . $e->getMessage() . '</error>');
            $status['mysql'] = ['error' => $e->getMessage()];
        }
        $this->newLine();

        // Check Redis service
        $this->comment('Redis Cache:');
        try {
            $redisStatus = $this->systemctl->getStatus('redis-server');
            $status['redis'] = $redisStatus;
            
            if ($redisStatus['active']) {
                $this->line('  Status: <info>✓ Running</info>');
                if ($redisStatus['pid']) {
                    $this->line('  PID: ' . $redisStatus['pid']);
                }
            } else {
                $this->line('  Status: <error>✗ Stopped</error>');
            }
        } catch (Exception $e) {
            $this->line('  Status: <error>✗ Error: ' . $e->getMessage() . '</error>');
            $status['redis'] = ['error' => $e->getMessage()];
        }
        $this->newLine();

        $this->info('═══════════════════════════════════════════════════════');

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));
        }

        return 0;
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
