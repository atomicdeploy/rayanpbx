<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemctlService;
use Exception;

class RayanPBXDiag extends Command
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
    protected $signature = 'rayanpbx:diag 
        {action : Action to perform (health-check|check-sip|check-ami|check-laravel|test-extension|fix-ami|reapply-ami)}
        {--port=5060 : SIP port to check}
        {--auto-fix : Automatically attempt to fix issues}
        {--extension= : Extension number for test-extension}
        {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run diagnostics and troubleshooting commands for RayanPBX';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        $validActions = ['health-check', 'check-sip', 'check-ami', 'check-laravel', 'test-extension', 'fix-ami', 'reapply-ami'];

        if (!in_array($action, $validActions)) {
            $this->error("❌ Invalid action: {$action}");
            $this->info("Valid actions: " . implode(', ', $validActions));
            return 1;
        }

        try {
            switch ($action) {
                case 'health-check':
                    return $this->healthCheck();
                case 'check-sip':
                    return $this->checkSip();
                case 'check-ami':
                    return $this->checkAmi();
                case 'check-laravel':
                    return $this->checkLaravel();
                case 'test-extension':
                    return $this->testExtension();
                case 'fix-ami':
                    return $this->fixAmi();
                case 'reapply-ami':
                    return $this->reapplyAmi();
            }
        } catch (Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Run comprehensive health check
     */
    private function healthCheck(): int
    {
        $this->printHeader('🏥 System Health Check');

        $results = [];
        $allPassed = true;

        // Check Asterisk
        $this->comment('Checking Asterisk Service...');
        try {
            if ($this->systemctl->isRunning('asterisk')) {
                $this->info('  ✅ Asterisk is running');
                $results['asterisk'] = ['status' => 'running', 'passed' => true];
            } else {
                $this->error('  ❌ Asterisk is not running');
                $results['asterisk'] = ['status' => 'stopped', 'passed' => false];
                $allPassed = false;
            }
        } catch (Exception $e) {
            $this->error('  ❌ Cannot check Asterisk: ' . $e->getMessage());
            $results['asterisk'] = ['status' => 'error', 'passed' => false, 'error' => $e->getMessage()];
            $allPassed = false;
        }

        // Check Database
        $this->newLine();
        $this->comment('Checking Database Connection...');
        try {
            \DB::connection()->getPdo();
            $this->info('  ✅ Database connection successful');
            $results['database'] = ['status' => 'connected', 'passed' => true];
        } catch (Exception $e) {
            $this->error('  ❌ Database connection failed');
            $results['database'] = ['status' => 'disconnected', 'passed' => false, 'error' => $e->getMessage()];
            $allPassed = false;
        }

        // Check API
        $this->newLine();
        $this->comment('Checking API Server...');
        try {
            $response = @file_get_contents('http://localhost:8000/api/health', false, stream_context_create([
                'http' => ['timeout' => 5]
            ]));
            if ($response !== false) {
                $this->info('  ✅ API server is responding');
                $results['api'] = ['status' => 'responding', 'passed' => true];
            } else {
                $this->warn('  ⚠️  API server not responding');
                $results['api'] = ['status' => 'not_responding', 'passed' => false];
            }
        } catch (Exception $e) {
            $this->warn('  ⚠️  Cannot check API: ' . $e->getMessage());
            $results['api'] = ['status' => 'error', 'passed' => false, 'error' => $e->getMessage()];
        }

        // Check SIP Port
        $this->newLine();
        $this->comment('Checking SIP Port (5060)...');
        if ($this->isPortListening(5060)) {
            $this->info('  ✅ SIP port 5060 is listening');
            $results['sip_port'] = ['status' => 'listening', 'passed' => true];
        } else {
            $this->error('  ❌ SIP port 5060 is not listening');
            $results['sip_port'] = ['status' => 'not_listening', 'passed' => false];
            $allPassed = false;
        }

        // Check AMI Port
        $this->newLine();
        $this->comment('Checking AMI Port (5038)...');
        if ($this->isPortListening(5038)) {
            $this->info('  ✅ AMI port 5038 is listening');
            $results['ami_port'] = ['status' => 'listening', 'passed' => true];
        } else {
            $this->error('  ❌ AMI port 5038 is not listening');
            $results['ami_port'] = ['status' => 'not_listening', 'passed' => false];
            $allPassed = false;
        }

        $this->newLine();

        if ($this->option('json')) {
            $this->line(json_encode([
                'passed' => $allPassed,
                'timestamp' => now()->toIso8601String(),
                'checks' => $results,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->printSeparator();
            if ($allPassed) {
                $this->info('✅ All health checks passed');
            } else {
                $this->error('❌ Some health checks failed');
            }
            $this->printSeparator();
        }

        return $allPassed ? 0 : 1;
    }

    /**
     * Check SIP port status
     */
    private function checkSip(): int
    {
        $port = (int) $this->option('port');
        $autoFix = $this->option('auto-fix');

        $this->printHeader('📞 SIP Port Health Check');

        // Check if Asterisk is running
        $this->comment('Checking Asterisk service...');
        try {
            if (!$this->systemctl->isRunning('asterisk')) {
                $this->error('  ❌ Asterisk service is not running');
                if ($autoFix) {
                    $this->info('  🔧 Attempting to start Asterisk...');
                    if ($this->systemctl->start('asterisk')) {
                        sleep(3);
                        $this->info('  ✅ Asterisk service started');
                    } else {
                        $this->error('  ❌ Failed to start Asterisk');
                        return 1;
                    }
                } else {
                    return 1;
                }
            } else {
                $this->info('  ✅ Asterisk service is running');
            }
        } catch (Exception $e) {
            $this->error('  ❌ Error checking Asterisk: ' . $e->getMessage());
            return 1;
        }

        // Check PJSIP transports
        $this->newLine();
        $this->comment('Checking PJSIP transports...');
        $transports = $this->systemctl->execAsteriskCLI('pjsip show transports');
        if (strpos($transports, 'transport-udp') !== false || strpos($transports, 'transport-tcp') !== false) {
            $this->info('  ✅ PJSIP transports are configured');
        } else {
            $this->warn('  ⚠️  PJSIP transports not configured');
        }

        // Check if port is listening
        $this->newLine();
        $this->comment("Checking if port {$port} is listening...");
        if ($this->isPortListening($port)) {
            $this->info("  ✅ SIP port {$port} is listening");
            
            // Get server IP for display
            $serverIp = trim(shell_exec("hostname -I | awk '{print \$1}'") ?? '127.0.0.1');
            
            $this->newLine();
            $this->info('🚀 SIP Endpoint for clients:');
            $this->line("  Address:  {$serverIp}:{$port}");
            $this->line("  Protocol: UDP/TCP");
            $this->comment("  Configure your SIP phones to connect to this address");
            
            return 0;
        } else {
            $this->error("  ❌ SIP port {$port} is NOT listening");
            $this->newLine();
            $this->warn('  ⚠️  SIP clients will get "connection refused" when connecting!');
            $this->newLine();
            $this->comment('Possible causes:');
            $this->line('  1. PJSIP transport not configured correctly');
            $this->line('  2. Another process using port ' . $port);
            $this->line('  3. Firewall blocking the port');
            $this->line('  4. Asterisk failed to bind to the port');
            
            if ($autoFix) {
                $this->newLine();
                $this->info('🔧 Attempting to fix by reloading PJSIP...');
                $this->systemctl->execAsteriskCLI('pjsip reload');
                sleep(3);
                
                if ($this->isPortListening($port)) {
                    $this->info("  ✅ SIP port {$port} is now listening after reload");
                    return 0;
                } else {
                    $this->error('  ❌ Could not fix SIP port issue automatically');
                }
            }
            
            return 1;
        }
    }

    /**
     * Check AMI health
     */
    private function checkAmi(): int
    {
        $autoFix = $this->option('auto-fix');

        $this->printHeader('🔌 AMI Socket Health Check');

        $amiHost = config('services.asterisk.ami_host', '127.0.0.1');
        $amiPort = (int) config('services.asterisk.ami_port', 5038);
        $amiUsername = config('services.asterisk.ami_username', 'admin');
        $amiSecret = config('services.asterisk.ami_secret', 'rayanpbx_ami_secret');

        // Check if AMI port is listening
        $this->comment('Checking AMI port...');
        if (!$this->isPortListening($amiPort)) {
            $this->error("  ❌ AMI port {$amiPort} is not listening");
            return 1;
        }
        $this->info("  ✅ AMI port {$amiPort} is listening");

        // Test AMI connection
        $this->newLine();
        $this->comment('Testing AMI authentication...');
        $amiResult = $this->testAmiConnection($amiHost, $amiPort, $amiUsername, $amiSecret);
        
        if ($amiResult['success']) {
            $this->info('  ✅ AMI authentication successful');
            return 0;
        } else {
            $this->error('  ❌ AMI authentication failed');
            if (isset($amiResult['error'])) {
                $this->line("  Error: {$amiResult['error']}");
            }
            
            if ($autoFix) {
                $this->newLine();
                $this->info('🔧 Attempting to fix AMI configuration...');
                return $this->fixAmi();
            }
            
            return 1;
        }
    }

    /**
     * Check Laravel health
     */
    private function checkLaravel(): int
    {
        $autoFix = $this->option('auto-fix');

        $this->printHeader('🔍 Laravel Backend Health Check');

        $backendDir = base_path();

        // Check vendor directory
        $this->comment('Checking vendor directory...');
        if (!is_dir($backendDir . '/vendor')) {
            $this->error('  ❌ Vendor directory not found');
            if ($autoFix) {
                $this->info('  🔧 Running composer install...');
                exec("cd {$backendDir} && composer install --no-dev --optimize-autoloader 2>&1", $output, $returnCode);
                if ($returnCode === 0) {
                    $this->info('  ✅ Composer dependencies installed');
                } else {
                    $this->error('  ❌ Failed to install dependencies');
                    return 1;
                }
            } else {
                $this->info("  Run: cd {$backendDir} && composer install");
                return 1;
            }
        } else {
            $this->info('  ✅ Vendor directory exists');
        }

        // Check autoload.php
        $this->newLine();
        $this->comment('Checking autoload...');
        if (!file_exists($backendDir . '/vendor/autoload.php')) {
            $this->error('  ❌ Autoload.php not found');
            if ($autoFix) {
                $this->info('  🔧 Running composer dump-autoload...');
                exec("cd {$backendDir} && composer dump-autoload -o 2>&1", $output, $returnCode);
                if ($returnCode === 0) {
                    $this->info('  ✅ Autoload regenerated');
                } else {
                    $this->error('  ❌ Failed to regenerate autoload');
                    return 1;
                }
            } else {
                $this->info("  Run: cd {$backendDir} && composer dump-autoload -o");
                return 1;
            }
        } else {
            $this->info('  ✅ Autoload.php exists');
        }

        // Test critical class loading
        $this->newLine();
        $this->comment('Testing critical class loading...');
        $criticalClasses = [
            'App\\Models\\Extension',
            'App\\Models\\Trunk',
            'App\\Services\\SystemctlService',
        ];

        $failedClasses = [];
        foreach ($criticalClasses as $class) {
            if (!class_exists($class)) {
                $failedClasses[] = $class;
            }
        }

        if (empty($failedClasses)) {
            $this->info('  ✅ All critical classes are loadable');
            return 0;
        } else {
            $this->error('  ❌ Failed to load classes: ' . implode(', ', $failedClasses));
            if ($autoFix) {
                $this->info('  🔧 Regenerating autoload...');
                exec("cd {$backendDir} && composer dump-autoload -o 2>&1", $output, $returnCode);
                // Retest
                $stillFailed = [];
                foreach ($failedClasses as $class) {
                    if (!class_exists($class, true)) {
                        $stillFailed[] = $class;
                    }
                }
                if (empty($stillFailed)) {
                    $this->info('  ✅ Autoload fixed - all classes now loadable');
                    return 0;
                } else {
                    $this->error('  ❌ Classes still not loadable after autoload regeneration');
                    return 1;
                }
            }
            return 1;
        }
    }

    /**
     * Test extension registration
     */
    private function testExtension(): int
    {
        $extension = $this->option('extension');

        if (!$extension) {
            $extension = $this->ask('Extension number to test');
        }

        if (!$extension) {
            $this->error('❌ Extension number required');
            return 1;
        }

        $this->printHeader("🔍 Testing Extension: {$extension}");

        // Check registration via Asterisk CLI
        $output = $this->systemctl->execAsteriskCLI("pjsip show endpoint {$extension}");

        if (strpos($output, 'Unavailable') !== false || strpos($output, 'Not found') !== false || empty($output)) {
            $this->error('  ❌ Extension is not registered');
            $this->newLine();
            $this->comment('Possible causes:');
            $this->line('  1. Extension not configured in database');
            $this->line('  2. Phone/softphone not registered');
            $this->line('  3. Incorrect credentials');
            return 1;
        }

        $this->info('  ✅ Extension is registered');
        
        // Extract and display contact and status info
        if (preg_match('/Contact:.*$/m', $output, $matches)) {
            $this->line('  ' . trim($matches[0]));
        }
        if (preg_match('/Status:.*$/m', $output, $matches)) {
            $this->line('  ' . trim($matches[0]));
        }

        return 0;
    }

    /**
     * Fix AMI configuration
     */
    private function fixAmi(): int
    {
        $this->printHeader('🔧 Fix AMI Credentials');

        $managerConf = '/etc/asterisk/manager.conf';
        $envFile = base_path('.env');
        
        // Read current AMI secret from .env
        $amiSecret = config('services.asterisk.ami_secret', 'rayanpbx_ami_secret');
        $amiUsername = config('services.asterisk.ami_username', 'admin');

        if (!file_exists($managerConf)) {
            $this->info('Creating manager.conf...');
        } else {
            $this->info('Updating manager.conf...');
            // Backup current config
            $backupFile = $managerConf . '.backup.' . date('YmdHis');
            copy($managerConf, $backupFile);
            $this->info("  Backup saved to: {$backupFile}");
        }

        // Create/update manager.conf
        $managerContent = <<<EOF
; Asterisk Manager Interface (AMI) Configuration
; Managed by RayanPBX

[general]
enabled = yes
port = 5038
bindaddr = 127.0.0.1

[{$amiUsername}]
secret = {$amiSecret}
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.255
read = all
write = all
EOF;

        if (file_put_contents($managerConf, $managerContent) === false) {
            $this->error('❌ Failed to write manager.conf');
            $this->info('You may need to run this command with sudo');
            return 1;
        }

        // Set ownership
        exec("chown asterisk:asterisk {$managerConf} 2>/dev/null");
        exec("chmod 640 {$managerConf} 2>/dev/null");

        $this->info('  ✅ manager.conf updated');

        // Reload Asterisk manager
        $this->newLine();
        $this->comment('Reloading Asterisk manager...');
        $this->systemctl->execAsteriskCLI('manager reload');
        sleep(2);

        // Verify the fix
        $this->comment('Verifying AMI connection...');
        $amiResult = $this->testAmiConnection('127.0.0.1', 5038, $amiUsername, $amiSecret);
        
        if ($amiResult['success']) {
            $this->info('  ✅ AMI connection and authentication now working!');
            return 0;
        } else {
            $this->warn('  ⚠️  AMI may need a full Asterisk restart');
            $this->info('  Try: systemctl restart asterisk');
            return 1;
        }
    }

    /**
     * Reapply AMI credentials
     */
    private function reapplyAmi(): int
    {
        $this->printHeader('🔧 Reapply AMI Credentials');

        // This essentially does the same as fix-ami but with more verification
        $managerConf = '/etc/asterisk/manager.conf';
        
        $amiUsername = config('services.asterisk.ami_username', 'admin');
        $amiSecret = config('services.asterisk.ami_secret', 'rayanpbx_ami_secret');

        $this->info("Expected AMI username from .env: {$amiUsername}");

        if (!file_exists($managerConf)) {
            $this->error('manager.conf not found');
            $this->info('Run: php artisan rayanpbx:diag fix-ami');
            return 1;
        }

        // Parse current values
        $this->newLine();
        $this->comment('Current manager.conf configuration:');
        
        $content = file_get_contents($managerConf);
        $lines = explode("\n", $content);
        
        $inGeneral = false;
        $inUserSection = false;
        $issues = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '[general]') {
                $inGeneral = true;
                $inUserSection = false;
            } elseif ($line === "[{$amiUsername}]") {
                $inGeneral = false;
                $inUserSection = true;
            } elseif (preg_match('/^\[/', $line)) {
                $inGeneral = false;
                $inUserSection = false;
            }

            if ($inGeneral && preg_match('/^enabled\s*=\s*(.+)$/', $line, $m)) {
                $value = trim($m[1]);
                $this->line("  enabled = {$value}");
                if ($value !== 'yes') {
                    $issues[] = "AMI is not enabled (current: '{$value}', expected: 'yes')";
                }
            }
            if ($inGeneral && preg_match('/^port\s*=\s*(.+)$/', $line, $m)) {
                $value = trim($m[1]);
                $this->line("  port = {$value}");
                if ($value !== '5038') {
                    $issues[] = "AMI port is incorrect (current: '{$value}', expected: '5038')";
                }
            }
        }

        if (empty($issues)) {
            $this->newLine();
            $this->info('✅ All AMI configuration values appear correct!');
            
            // Test AMI connection
            $this->newLine();
            $this->comment('Testing AMI connection...');
            $amiResult = $this->testAmiConnection('127.0.0.1', 5038, $amiUsername, $amiSecret);
            
            if ($amiResult['success']) {
                $this->info('  ✅ AMI connection and authentication successful!');
                return 0;
            } else {
                $this->error('  ❌ AMI authentication failed');
                $this->info('  Try: asterisk -rx "manager reload"');
                return 1;
            }
        }

        $this->newLine();
        $this->warn('Issues found: ' . count($issues));
        foreach ($issues as $issue) {
            $this->line("  • {$issue}");
        }

        $this->newLine();
        $this->info('Applying fixes...');
        return $this->fixAmi();
    }

    /**
     * Check if a port is listening
     */
    private function isPortListening(int $port): bool
    {
        // Validate port to be a proper integer to prevent command injection
        if ($port < 1 || $port > 65535) {
            return false;
        }
        
        // Use ss command (modern replacement for netstat)
        // Since port is validated as integer, we can safely use it directly
        $command = "ss -tuln | grep -E ':{$port}([[:space:]]|\$)' 2>/dev/null";
        exec($command, $output);
        return !empty($output);
    }

    /**
     * Test AMI connection
     */
    private function testAmiConnection(string $host, int $port, string $username, string $secret): array
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        
        if (!$socket) {
            return ['success' => false, 'error' => "Cannot connect: {$errstr}"];
        }

        // Read banner
        $banner = fgets($socket, 1024);
        
        // Send login
        $loginCommand = "Action: Login\r\nUsername: {$username}\r\nSecret: {$secret}\r\n\r\n";
        fwrite($socket, $loginCommand);
        
        // Read response
        $response = '';
        $startTime = time();
        while (!feof($socket) && (time() - $startTime) < 5) {
            $line = fgets($socket, 1024);
            $response .= $line;
            if (trim($line) === '') {
                break;
            }
        }
        
        fclose($socket);
        
        if (stripos($response, 'Success') !== false) {
            return ['success' => true];
        } elseif (stripos($response, 'Authentication failed') !== false) {
            return ['success' => false, 'error' => 'Authentication failed'];
        } else {
            return ['success' => false, 'error' => 'Unknown response'];
        }
    }

    /**
     * Print a styled header
     */
    private function printHeader(string $title): void
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════════');
        $this->info("  {$title}");
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');
    }

    /**
     * Print a separator line
     */
    private function printSeparator(): void
    {
        $this->info('═══════════════════════════════════════════════════════');
    }
}
