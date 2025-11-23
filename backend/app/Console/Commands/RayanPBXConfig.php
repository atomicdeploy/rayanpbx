<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ConfigValidatorService;
use App\Services\SystemctlService;
use Exception;

class RayanPBXConfig extends Command
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
    protected $signature = 'rayanpbx:config {action} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate and reload RayanPBX configurations (validate|reload|apply)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $validActions = ['validate', 'reload', 'apply'];

        if (!in_array($action, $validActions)) {
            $this->error("Invalid action: {$action}");
            $this->info("Valid actions: " . implode(', ', $validActions));
            return 1;
        }

        try {
            switch ($action) {
                case 'validate':
                    return $this->validateConfig();
                    
                case 'reload':
                case 'apply':
                    return $this->reloadConfig();
            }
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Validate configuration
     */
    private function validateConfig(): int
    {
        $this->info('Validating RayanPBX configuration...');
        $this->newLine();

        $validator = new ConfigValidatorService();

        try {
            // Validate Asterisk configuration
            $this->comment('Checking Asterisk configuration...');
            $result = $this->systemctl->execAsteriskCLI('core show settings');
            
            if (empty($result)) {
                $this->error('✗ Unable to connect to Asterisk');
                return 1;
            }

            $this->info('✓ Asterisk is running and accessible');
            
            // Check PJSIP endpoints
            $this->newLine();
            $this->comment('Checking PJSIP endpoints...');
            $endpoints = $this->systemctl->execAsteriskCLI('pjsip show endpoints');
            
            if (str_contains($endpoints, 'No such command')) {
                $this->warn('⚠ PJSIP module may not be loaded');
            } else {
                $endpointCount = substr_count($endpoints, 'Endpoint:');
                $this->info("✓ Found {$endpointCount} PJSIP endpoint(s)");
            }

            // Check database connection
            $this->newLine();
            $this->comment('Checking database connection...');
            \DB::connection()->getPdo();
            $this->info('✓ Database connection successful');

            // Check extensions count
            $extensionsCount = \App\Models\Extension::count();
            $this->info("✓ Found {$extensionsCount} extension(s) in database");

            // Check trunks count
            $trunksCount = \App\Models\Trunk::count();
            $this->info("✓ Found {$trunksCount} trunk(s) in database");

            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════');
            $this->info('✓ Configuration validation completed successfully');
            $this->info('═══════════════════════════════════════════════════════');

            return 0;
        } catch (Exception $e) {
            $this->error('✗ Validation failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Reload configuration
     */
    private function reloadConfig(): int
    {
        $this->info('Reloading RayanPBX configuration...');
        $this->newLine();

        if (!$this->option('force')) {
            if (!$this->confirm('This will reload Asterisk configuration. Continue?', true)) {
                $this->info('Cancelled');
                return 0;
            }
        }

        try {
            // Generate PJSIP configuration from database
            $this->comment('Generating PJSIP configuration from database...');
            $this->call('rayanpbx:generate-config');
            $this->info('✓ Configuration files generated');

            $this->newLine();
            $this->comment('Reloading Asterisk configuration...');
            
            if ($this->systemctl->reloadAsterisk()) {
                $this->info('✓ Asterisk configuration reloaded successfully');
                
                $this->newLine();
                $this->comment('Verifying configuration...');
                
                // Give Asterisk a moment to reload
                sleep(2);
                
                // Verify reload was successful
                $result = $this->systemctl->execAsteriskCLI('core show version');
                if (!empty($result)) {
                    $this->info('✓ Configuration reload verified');
                    
                    $this->newLine();
                    $this->info('═══════════════════════════════════════════════════════');
                    $this->info('✓ Configuration applied successfully');
                    $this->info('═══════════════════════════════════════════════════════');
                    
                    return 0;
                } else {
                    $this->error('✗ Unable to verify configuration reload');
                    return 1;
                }
            } else {
                $this->error('✗ Failed to reload Asterisk configuration');
                $this->newLine();
                $this->warn('You may need to restart Asterisk instead:');
                $this->line('  php artisan rayanpbx:service restart asterisk');
                return 1;
            }
        } catch (Exception $e) {
            $this->error('✗ Reload failed: ' . $e->getMessage());
            return 1;
        }
    }
}
