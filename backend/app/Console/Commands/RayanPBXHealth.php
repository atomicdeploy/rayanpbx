<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemctlService;
use App\Models\Extension;
use App\Models\Trunk;
use Exception;

class RayanPBXHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:health {--json : Output as JSON} {--detailed : Show detailed information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run comprehensive health checks on RayanPBX system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $results = [];
        $allPassed = true;

        if (!$this->option('json')) {
            $this->info('═══════════════════════════════════════════════════════');
            $this->info('   RayanPBX Health Check');
            $this->info('═══════════════════════════════════════════════════════');
            $this->newLine();
        }

        // Check services
        $results['services'] = $this->checkServices();
        if (!$results['services']['passed']) {
            $allPassed = false;
        }

        // Check database
        $results['database'] = $this->checkDatabase();
        if (!$results['database']['passed']) {
            $allPassed = false;
        }

        // Check Asterisk
        $results['asterisk'] = $this->checkAsterisk();
        if (!$results['asterisk']['passed']) {
            $allPassed = false;
        }

        // Check disk space
        $results['disk'] = $this->checkDiskSpace();
        if (!$results['disk']['passed']) {
            $allPassed = false;
        }

        // Check memory
        $results['memory'] = $this->checkMemory();
        if (!$results['memory']['passed']) {
            $allPassed = false;
        }

        // Check network ports
        $results['ports'] = $this->checkPorts();
        if (!$results['ports']['passed']) {
            $allPassed = false;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'passed' => $allPassed,
                'timestamp' => now()->toIso8601String(),
                'checks' => $results,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════');
            if ($allPassed) {
                $this->info('✓ All health checks passed');
            } else {
                $this->error('✗ Some health checks failed');
            }
            $this->info('═══════════════════════════════════════════════════════');
        }

        return $allPassed ? 0 : 1;
    }

    /**
     * Check system services
     */
    private function checkServices(): array
    {
        if (!$this->option('json')) {
            $this->comment('Checking services...');
        }

        $systemctl = new SystemctlService();
        $services = ['asterisk', 'rayanpbx-api', 'mysql', 'redis-server'];
        $serviceNames = ['Asterisk', 'RayanPBX API', 'MySQL', 'Redis'];
        $results = [];
        $allRunning = true;

        foreach ($services as $index => $service) {
            try {
                $running = $systemctl->isRunning($service);
                $results[$service] = [
                    'running' => $running,
                    'name' => $serviceNames[$index],
                ];

                if (!$this->option('json')) {
                    if ($running) {
                        $this->info("  ✓ {$serviceNames[$index]} is running");
                    } else {
                        $this->error("  ✗ {$serviceNames[$index]} is not running");
                        $allRunning = false;
                    }
                }
            } catch (Exception $e) {
                $results[$service] = [
                    'running' => false,
                    'error' => $e->getMessage(),
                ];
                $allRunning = false;
            }
        }

        if (!$this->option('json')) {
            $this->newLine();
        }

        return [
            'passed' => $allRunning,
            'services' => $results,
        ];
    }

    /**
     * Check database connection and data
     */
    private function checkDatabase(): array
    {
        if (!$this->option('json')) {
            $this->comment('Checking database...');
        }

        try {
            \DB::connection()->getPdo();
            $extensionsCount = Extension::count();
            $trunksCount = Trunk::count();

            if (!$this->option('json')) {
                $this->info('  ✓ Database connection successful');
                $this->info("  ✓ Extensions: {$extensionsCount}");
                $this->info("  ✓ Trunks: {$trunksCount}");
                $this->newLine();
            }

            return [
                'passed' => true,
                'connected' => true,
                'extensions_count' => $extensionsCount,
                'trunks_count' => $trunksCount,
            ];
        } catch (Exception $e) {
            if (!$this->option('json')) {
                $this->error('  ✗ Database connection failed: ' . $e->getMessage());
                $this->newLine();
            }

            return [
                'passed' => false,
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check Asterisk functionality
     */
    private function checkAsterisk(): array
    {
        if (!$this->option('json')) {
            $this->comment('Checking Asterisk...');
        }

        $systemctl = new SystemctlService();

        try {
            $version = $systemctl->execAsteriskCLI('core show version');
            $calls = $systemctl->execAsteriskCLI('core show calls');
            
            $versionMatch = preg_match('/Asterisk\s+([\d.]+)/', $version, $versionMatches);
            $callsMatch = preg_match('/(\d+)\s+active call/', $calls, $callMatches);
            
            $asteriskVersion = $versionMatch ? $versionMatches[1] : 'Unknown';
            $activeCalls = $callsMatch ? (int)$callMatches[1] : 0;

            if (!$this->option('json')) {
                $this->info("  ✓ Asterisk version: {$asteriskVersion}");
                $this->info("  ✓ Active calls: {$activeCalls}");
                $this->newLine();
            }

            return [
                'passed' => true,
                'version' => $asteriskVersion,
                'active_calls' => $activeCalls,
            ];
        } catch (Exception $e) {
            if (!$this->option('json')) {
                $this->error('  ✗ Asterisk check failed: ' . $e->getMessage());
                $this->newLine();
            }

            return [
                'passed' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check disk space
     */
    private function checkDiskSpace(): array
    {
        if (!$this->option('json')) {
            $this->comment('Checking disk space...');
        }

        $diskTotal = disk_total_space('/');
        $diskFree = disk_free_space('/');
        $diskUsed = $diskTotal - $diskFree;
        $diskUsedPercent = ($diskUsed / $diskTotal) * 100;

        $passed = $diskUsedPercent < 90;

        if (!$this->option('json')) {
            $diskTotalGB = round($diskTotal / 1024 / 1024 / 1024, 2);
            $diskFreeGB = round($diskFree / 1024 / 1024 / 1024, 2);
            $diskUsedGB = round($diskUsed / 1024 / 1024 / 1024, 2);

            if ($passed) {
                $this->info("  ✓ Disk space: {$diskFreeGB}GB free of {$diskTotalGB}GB (" . round($diskUsedPercent, 1) . "% used)");
            } else {
                $this->error("  ✗ Disk space: {$diskFreeGB}GB free of {$diskTotalGB}GB (" . round($diskUsedPercent, 1) . "% used)");
                $this->warn('    Warning: Disk usage is above 90%');
            }
            $this->newLine();
        }

        return [
            'passed' => $passed,
            'total_bytes' => $diskTotal,
            'free_bytes' => $diskFree,
            'used_percent' => round($diskUsedPercent, 2),
        ];
    }

    /**
     * Check memory usage
     */
    private function checkMemory(): array
    {
        if (!$this->option('json')) {
            $this->comment('Checking memory...');
        }

        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatch);

        $memTotal = isset($totalMatch[1]) ? (int)$totalMatch[1] * 1024 : 0;
        $memAvail = isset($availMatch[1]) ? (int)$availMatch[1] * 1024 : 0;
        $memUsed = $memTotal - $memAvail;
        $memUsedPercent = $memTotal > 0 ? ($memUsed / $memTotal) * 100 : 0;

        $passed = $memUsedPercent < 90;

        if (!$this->option('json')) {
            $memTotalGB = round($memTotal / 1024 / 1024 / 1024, 2);
            $memAvailGB = round($memAvail / 1024 / 1024 / 1024, 2);

            if ($passed) {
                $this->info("  ✓ Memory: {$memAvailGB}GB available of {$memTotalGB}GB (" . round($memUsedPercent, 1) . "% used)");
            } else {
                $this->error("  ✗ Memory: {$memAvailGB}GB available of {$memTotalGB}GB (" . round($memUsedPercent, 1) . "% used)");
                $this->warn('    Warning: Memory usage is above 90%');
            }
            $this->newLine();
        }

        return [
            'passed' => $passed,
            'total_bytes' => $memTotal,
            'available_bytes' => $memAvail,
            'used_percent' => round($memUsedPercent, 2),
        ];
    }

    /**
     * Check network ports
     */
    private function checkPorts(): array
    {
        if (!$this->option('json')) {
            $this->comment('Checking network ports...');
        }

        $ports = [
            '8000' => 'RayanPBX API',
            '3000' => 'Frontend',
            '5060' => 'SIP',
            '5038' => 'AMI',
        ];

        $results = [];
        $allListening = true;

        foreach ($ports as $port => $service) {
            exec("ss -tuln | grep -E ':$port([[:space:]]|$)' 2>/dev/null", $output, $returnCode);
            $listening = !empty($output);

            $results[$port] = [
                'service' => $service,
                'listening' => $listening,
            ];

            if (!$this->option('json')) {
                if ($listening) {
                    $this->info("  ✓ Port {$port} ({$service}) is listening");
                } else {
                    $this->warn("  ⚠ Port {$port} ({$service}) is not listening");
                }
            }
        }

        if (!$this->option('json')) {
            $this->newLine();
        }

        return [
            'passed' => true, // Ports not listening is a warning, not a failure
            'ports' => $results,
        ];
    }
}
