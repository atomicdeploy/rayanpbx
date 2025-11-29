<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Extension;
use App\Adapters\AsteriskAdapter;
use Illuminate\Support\Facades\Process;
use Exception;

class RayanPBXSipTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:sip-test {action} {extension?} {extension2?} 
                           {--server=127.0.0.1 : SIP server address}
                           {--port=5060 : SIP port}
                           {--password= : Extension password (or read from DB)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'SIP testing utilities (tools|install|register|call|options|full)';

    private $asterisk;
    private $scriptPath;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->asterisk = app(AsteriskAdapter::class);
        $this->scriptPath = base_path('../scripts/sip-test-suite.sh');
        
        $action = $this->argument('action');

        $validActions = ['tools', 'install', 'register', 'call', 'options', 'full'];

        if (!in_array($action, $validActions)) {
            $this->error("Invalid action: {$action}");
            $this->info("Valid actions: " . implode(', ', $validActions));
            return 1;
        }

        try {
            switch ($action) {
                case 'tools':
                    return $this->checkTools();
                    
                case 'install':
                    return $this->installTool();
                    
                case 'register':
                    return $this->testRegistration();
                    
                case 'call':
                    return $this->testCall();
                    
                case 'options':
                    return $this->testOptions();
                    
                case 'full':
                    return $this->fullTest();
            }
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Check available SIP testing tools
     */
    private function checkTools(): int
    {
        $this->info("🔍 Checking Available SIP Testing Tools");
        $this->newLine();
        
        $tools = [
            'pjsua' => 'Full SIP user agent (recommended for calls)',
            'sipsak' => 'SIP Swiss Army Knife (registration testing)',
            'sipexer' => 'Modern Go-based SIP tool',
            'sipp' => 'SIP performance testing',
        ];
        
        $available = [];
        $unavailable = [];
        
        foreach ($tools as $tool => $description) {
            $result = Process::run(['which', $tool]);
            $isAvailable = $result->successful();
            
            if ($isAvailable) {
                $available[] = ['✓', $tool, $description];
            } else {
                $unavailable[] = ['✗', $tool, $description];
            }
        }
        
        $this->table(
            ['Status', 'Tool', 'Description'],
            array_merge($available, $unavailable)
        );
        
        $this->newLine();
        
        if (count($available) === 0) {
            $this->warn("⚠️  No SIP testing tools installed!");
            $this->line("Install tools with: php artisan rayanpbx:sip-test install");
        } else {
            $this->info("✓ " . count($available) . " tool(s) available");
        }
        
        return 0;
    }

    /**
     * Install SIP testing tools
     */
    private function installTool(): int
    {
        $extension = $this->argument('extension');
        
        if (!$extension) {
            $extension = $this->choice(
                'Select tool to install',
                ['pjsua', 'sipsak', 'sipexer', 'sipp', 'all'],
                0
            );
        }
        
        if ($extension === 'all') {
            $this->info("Installing all SIP testing tools...");
            foreach (['pjsua', 'sipsak', 'sipexer', 'sipp'] as $tool) {
                $this->installSingleTool($tool);
            }
            return 0;
        }
        
        return $this->installSingleTool($extension);
    }
    
    /**
     * Install a single SIP testing tool
     * Note: Installation requires root privileges. Run this command as root or with sudo.
     */
    private function installSingleTool(string $tool): int
    {
        $this->info("Installing {$tool}...");
        $this->warn("⚠️  Note: Installation requires root privileges.");
        
        if (!file_exists($this->scriptPath)) {
            $this->error("SIP test suite script not found at: {$this->scriptPath}");
            $this->line("You can install manually using your package manager:");
            $this->line("  apt-get install {$tool} (Debian/Ubuntu)");
            $this->line("  yum install {$tool} (RHEL/CentOS)");
            return 1;
        }
        
        // Check if we have root privileges
        $uid = posix_getuid();
        if ($uid !== 0) {
            $this->warn("Running without root privileges. Installation may fail.");
            $this->line("Consider running: sudo php artisan rayanpbx:sip-test install {$tool}");
        }
        
        // Run installation script (requires root - user should run with sudo if needed)
        $result = Process::run([
            'bash',
            $this->scriptPath,
            'install',
            $tool
        ]);
        
        if ($result->successful()) {
            $this->info("✓ {$tool} installed successfully");
            return 0;
        } else {
            $this->error("✗ Failed to install {$tool}");
            $this->line($result->errorOutput());
            $this->newLine();
            $this->line("💡 Try running as root: sudo php artisan rayanpbx:sip-test install {$tool}");
            return 1;
        }
    }

    /**
     * Test SIP registration
     */
    private function testRegistration(): int
    {
        $extensionNumber = $this->argument('extension');
        
        if (!$extensionNumber) {
            $extensionNumber = $this->ask('Extension number to test');
        }
        
        // Get extension from database for password
        $extension = Extension::where('extension_number', $extensionNumber)->first();
        
        $password = $this->option('password');
        
        if (!$password && !$extension) {
            $this->error("Extension {$extensionNumber} not found in database. Please provide --password option.");
            return 1;
        }
        
        if (!$password) {
            $password = $extension->secret;
        }
        
        $server = $this->option('server');
        $port = $this->option('port');
        
        $this->info("🧪 Testing SIP Registration");
        $this->line("   Extension: {$extensionNumber}");
        $this->line("   Server: {$server}:{$port}");
        $this->newLine();
        
        if (!file_exists($this->scriptPath)) {
            // Use direct Asterisk verification instead
            return $this->testRegistrationDirect($extensionNumber, $password, $server, $port);
        }
        
        $result = Process::timeout(30)->run([
            'bash',
            $this->scriptPath,
            '-s', $server,
            '-p', (string)$port,
            'register',
            $extensionNumber,
            $password,
        ]);
        
        $output = $result->output();
        $this->line($output);
        
        if (str_contains($output, '✅ PASS') || str_contains($output, 'PASS')) {
            $this->newLine();
            $this->info("✓ Registration test passed!");
            return 0;
        } else {
            $this->newLine();
            $this->warn("⚠️  Registration test may have issues. Check output above.");
            return 1;
        }
    }
    
    /**
     * Test registration using Asterisk directly
     */
    private function testRegistrationDirect(string $extension, string $password, string $server, int $port): int
    {
        $this->line("Using direct Asterisk verification...");
        $this->newLine();
        
        // Check endpoint exists
        $endpointDetails = $this->asterisk->getPjsipEndpoint($extension);
        
        if ($endpointDetails === null) {
            $this->warn("⚠️  Endpoint {$extension} not found in Asterisk");
            $this->line("   Make sure the extension is properly configured and synced.");
            return 1;
        }
        
        $this->line("✓ Endpoint exists in Asterisk");
        
        // Check registration status
        $registrationStatus = $this->asterisk->getEndpointRegistrationStatus($extension);
        
        if ($registrationStatus['registered']) {
            $this->info("✓ Extension {$extension} is registered!");
            
            if (!empty($registrationStatus['details']['contacts'])) {
                $this->newLine();
                $this->line("   Registered Contacts:");
                foreach ($registrationStatus['details']['contacts'] as $contact) {
                    $this->line("   • {$contact['uri']} ({$contact['status']})");
                }
            }
            return 0;
        } else {
            $this->warn("⚠️  Extension {$extension} is not registered");
            $this->newLine();
            $this->line("   To test registration, configure a SIP client with:");
            $this->line("   • Username: {$extension}");
            $this->line("   • Password: (your configured password)");
            $this->line("   • Server: {$server}");
            $this->line("   • Port: {$port}");
            return 1;
        }
    }

    /**
     * Test SIP call between two extensions
     */
    private function testCall(): int
    {
        $fromExt = $this->argument('extension');
        $toExt = $this->argument('extension2');
        
        if (!$fromExt) {
            $fromExt = $this->ask('From extension number');
        }
        
        if (!$toExt) {
            $toExt = $this->ask('To extension number');
        }
        
        // Get extensions from database
        $fromExtension = Extension::where('extension_number', $fromExt)->first();
        $toExtension = Extension::where('extension_number', $toExt)->first();
        
        if (!$fromExtension) {
            $this->error("Extension {$fromExt} not found in database");
            return 1;
        }
        
        if (!$toExtension) {
            $this->error("Extension {$toExt} not found in database");
            return 1;
        }
        
        $server = $this->option('server');
        $port = $this->option('port');
        
        $this->info("🧪 Testing SIP Call");
        $this->line("   From: {$fromExt}");
        $this->line("   To: {$toExt}");
        $this->line("   Server: {$server}:{$port}");
        $this->newLine();
        
        if (!file_exists($this->scriptPath)) {
            $this->warn("SIP test suite script not found. Manual test required.");
            $this->newLine();
            $this->line("To test calls manually:");
            $this->line("1. Register a SIP client for {$fromExt}");
            $this->line("2. Register another SIP client for {$toExt}");
            $this->line("3. Dial {$toExt} from the first client");
            return 0;
        }
        
        $result = Process::timeout(60)->run([
            'bash',
            $this->scriptPath,
            '-s', $server,
            '-p', (string)$port,
            'call',
            $fromExt,
            $fromExtension->secret,
            $toExt,
            $toExtension->secret,
        ]);
        
        $output = $result->output();
        $this->line($output);
        
        if (str_contains($output, '✅ PASS') || str_contains($output, 'Call established')) {
            $this->newLine();
            $this->info("✓ Call test passed!");
            return 0;
        } else {
            $this->newLine();
            $this->warn("⚠️  Call test may have issues. Check output above.");
            return 1;
        }
    }

    /**
     * Test SIP OPTIONS (server responsiveness)
     */
    private function testOptions(): int
    {
        $server = $this->option('server');
        $port = $this->option('port');
        
        $this->info("🧪 Testing SIP Server Responsiveness (OPTIONS)");
        $this->line("   Server: {$server}:{$port}");
        $this->newLine();
        
        if (!file_exists($this->scriptPath)) {
            // Use Asterisk CLI to check via Process facade
            $this->line("Checking Asterisk SIP status...");
            
            $result = Process::timeout(10)->run([
                'asterisk', '-rx', 'pjsip show transports'
            ]);
            
            $output = $result->output();
            
            if ($result->successful() && $output && !str_contains($output, 'No objects')) {
                $this->line($output);
                $this->info("✓ PJSIP transports are configured");
                return 0;
            } else {
                $this->warn("⚠️  No PJSIP transports found");
                return 1;
            }
        }
        
        $result = Process::timeout(10)->run([
            'bash',
            $this->scriptPath,
            '-s', $server,
            '-p', (string)$port,
            'options',
        ]);
        
        $output = $result->output();
        $this->line($output);
        
        if (str_contains($output, '✅ PASS') || str_contains($output, 'responsive')) {
            $this->newLine();
            $this->info("✓ SIP server is responsive!");
            return 0;
        } else {
            $this->newLine();
            $this->warn("⚠️  SIP server may not be responding properly.");
            return 1;
        }
    }

    /**
     * Run full test suite
     */
    private function fullTest(): int
    {
        $ext1 = $this->argument('extension');
        $ext2 = $this->argument('extension2');
        
        if (!$ext1) {
            $ext1 = $this->ask('First extension number');
        }
        
        if (!$ext2) {
            $ext2 = $this->ask('Second extension number');
        }
        
        // Get extensions from database
        $extension1 = Extension::where('extension_number', $ext1)->first();
        $extension2 = Extension::where('extension_number', $ext2)->first();
        
        if (!$extension1) {
            $this->error("Extension {$ext1} not found in database");
            return 1;
        }
        
        if (!$extension2) {
            $this->error("Extension {$ext2} not found in database");
            return 1;
        }
        
        $server = $this->option('server');
        $port = $this->option('port');
        
        $this->info("🧪 Running Full SIP Test Suite");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("   Extension 1: {$ext1}");
        $this->line("   Extension 2: {$ext2}");
        $this->line("   Server: {$server}:{$port}");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->newLine();
        
        $passed = 0;
        $failed = 0;
        
        // Test 1: Server responsiveness
        $this->line("Test 1: Server Responsiveness");
        if ($this->testOptions() === 0) {
            $passed++;
        } else {
            $failed++;
        }
        $this->newLine();
        
        // Test 2: Extension 1 registration
        $this->line("Test 2: Extension 1 Registration ({$ext1})");
        if ($this->testRegistrationDirect($ext1, $extension1->secret, $server, (int)$port) === 0) {
            $passed++;
        } else {
            $failed++;
        }
        $this->newLine();
        
        // Test 3: Extension 2 registration
        $this->line("Test 3: Extension 2 Registration ({$ext2})");
        if ($this->testRegistrationDirect($ext2, $extension2->secret, $server, (int)$port) === 0) {
            $passed++;
        } else {
            $failed++;
        }
        
        // Summary
        $this->newLine();
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("   Test Summary");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("   Passed: {$passed}");
        $this->warn("   Failed: {$failed}");
        $this->line("   Total:  " . ($passed + $failed));
        
        return $failed > 0 ? 1 : 0;
    }
}
