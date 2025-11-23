<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Extension;
use App\Models\Trunk;
use Illuminate\Support\Facades\File;
use Exception;

class RayanPBXGenerateConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:generate-config {--dry-run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Asterisk configuration files from database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        try {
            $this->info('Generating Asterisk configuration from database...');
            $this->newLine();

            // Generate PJSIP configuration
            $this->comment('Generating PJSIP configuration...');
            $pjsipConfig = $this->generatePjsipConfig();
            
            if ($dryRun) {
                $this->info('PJSIP Configuration (dry-run):');
                $this->line($pjsipConfig);
                $this->newLine();
            } else {
                $configPath = '/etc/asterisk/pjsip_custom.conf';
                
                if (is_writable(dirname($configPath))) {
                    File::put($configPath, $pjsipConfig);
                    $this->info("✓ PJSIP configuration written to {$configPath}");
                } else {
                    $this->warn("⚠ Cannot write to {$configPath}, saving to local directory");
                    File::put(storage_path('app/pjsip_custom.conf'), $pjsipConfig);
                    $this->info('✓ Configuration saved to ' . storage_path('app/pjsip_custom.conf'));
                }
            }

            // Generate extensions configuration
            $this->comment('Generating extensions configuration...');
            $extensionsConfig = $this->generateExtensionsConfig();
            
            if ($dryRun) {
                $this->info('Extensions Configuration (dry-run):');
                $this->line($extensionsConfig);
                $this->newLine();
            } else {
                $configPath = '/etc/asterisk/extensions_custom.conf';
                
                if (is_writable(dirname($configPath))) {
                    File::put($configPath, $extensionsConfig);
                    $this->info("✓ Extensions configuration written to {$configPath}");
                } else {
                    $this->warn("⚠ Cannot write to {$configPath}, saving to local directory");
                    File::put(storage_path('app/extensions_custom.conf'), $extensionsConfig);
                    $this->info('✓ Configuration saved to ' . storage_path('app/extensions_custom.conf'));
                }
            }

            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════');
            $this->info('✓ Configuration files generated successfully');
            $this->info('═══════════════════════════════════════════════════════');

            if (!$dryRun) {
                $this->newLine();
                $this->info('To apply the configuration, run:');
                $this->line('  php artisan rayanpbx:config reload');
            }

            return 0;
        } catch (Exception $e) {
            $this->error('✗ Failed to generate configuration: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Generate PJSIP configuration
     */
    private function generatePjsipConfig(): string
    {
        $config = ";; Auto-generated PJSIP configuration\n";
        $config .= ";; Generated at: " . now()->toDateTimeString() . "\n";
        $config .= ";; Do not edit manually - managed by RayanPBX\n\n";

        // Generate extension endpoints
        $extensions = Extension::where('enabled', true)->get();
        
        $config .= ";; ========================================\n";
        $config .= ";; Extensions\n";
        $config .= ";; ========================================\n\n";

        foreach ($extensions as $extension) {
            $config .= "[{$extension->extension_number}]\n";
            $config .= "type=endpoint\n";
            $config .= "context={$extension->context}\n";
            $config .= "disallow=all\n";
            
            $codecs = $extension->codecs ?? ['ulaw', 'alaw'];
            foreach ($codecs as $codec) {
                $config .= "allow={$codec}\n";
            }
            
            $config .= "auth={$extension->extension_number}\n";
            $config .= "aors={$extension->extension_number}\n";
            
            if ($extension->caller_id) {
                $config .= "callerid={$extension->caller_id}\n";
            }
            
            $config .= "\n";

            // AOR (Address of Record)
            $config .= "[{$extension->extension_number}]\n";
            $config .= "type=aor\n";
            $config .= "max_contacts={$extension->max_contacts}\n";
            $config .= "\n";

            // Authentication
            $config .= "[{$extension->extension_number}]\n";
            $config .= "type=auth\n";
            $config .= "auth_type=userpass\n";
            $config .= "username={$extension->extension_number}\n";
            $config .= "password={$extension->secret}\n";
            $config .= "\n";
        }

        // Generate trunk endpoints
        $trunks = Trunk::where('enabled', true)->get();
        
        if ($trunks->isNotEmpty()) {
            $config .= ";; ========================================\n";
            $config .= ";; Trunks\n";
            $config .= ";; ========================================\n\n";

            foreach ($trunks as $trunk) {
                $config .= "[{$trunk->name}]\n";
                $config .= "type=endpoint\n";
                $config .= "context={$trunk->context}\n";
                $config .= "disallow=all\n";
                
                $codecs = $trunk->codecs ?? ['ulaw', 'alaw'];
                foreach ($codecs as $codec) {
                    $config .= "allow={$codec}\n";
                }
                
                $config .= "aors={$trunk->name}\n";
                
                if ($trunk->username) {
                    $config .= "outbound_auth={$trunk->name}\n";
                }
                
                $config .= "\n";

                // AOR
                $config .= "[{$trunk->name}]\n";
                $config .= "type=aor\n";
                $config .= "contact=sip:{$trunk->host}:{$trunk->port}\n";
                $config .= "\n";

                // Authentication (if required)
                if ($trunk->username && $trunk->secret) {
                    $config .= "[{$trunk->name}]\n";
                    $config .= "type=auth\n";
                    $config .= "auth_type=userpass\n";
                    $config .= "username={$trunk->username}\n";
                    $config .= "password={$trunk->secret}\n";
                    $config .= "\n";
                }
            }
        }

        return $config;
    }

    /**
     * Generate extensions dialplan configuration
     */
    private function generateExtensionsConfig(): string
    {
        $config = ";; Auto-generated extensions configuration\n";
        $config .= ";; Generated at: " . now()->toDateTimeString() . "\n";
        $config .= ";; Do not edit manually - managed by RayanPBX\n\n";

        $extensions = Extension::where('enabled', true)->get();
        
        $config .= ";; ========================================\n";
        $config .= ";; Default context for extensions\n";
        $config .= ";; ========================================\n\n";

        $config .= "[default]\n";
        
        foreach ($extensions as $extension) {
            $config .= "exten => {$extension->extension_number},1,NoOp(Incoming call for {$extension->name})\n";
            $config .= " same => n,Dial(PJSIP/{$extension->extension_number},30)\n";
            $config .= " same => n,Hangup()\n";
        }

        $config .= "\n";

        // Add trunk routing if trunks exist
        $trunks = Trunk::where('enabled', true)->orderBy('priority', 'desc')->get();
        
        if ($trunks->isNotEmpty()) {
            $config .= ";; ========================================\n";
            $config .= ";; Outbound routing via trunks\n";
            $config .= ";; ========================================\n\n";

            $config .= ";; Pattern for external calls\n";
            $config .= ";; Note: '_X.' matches any number - customize this pattern based on your needs\n";
            $config .= ";; Examples: '_9NXXNXXXXXX' for US numbers with 9 prefix\n";
            $config .= ";;          '_00X.' for international calls with 00 prefix\n";
            $config .= "exten => _X.,1,NoOp(Outbound call to \${EXTEN})\n";
            
            foreach ($trunks as $trunk) {
                $config .= " same => n,Dial(PJSIP/\${EXTEN}@{$trunk->name})\n";
            }
            
            $config .= " same => n,Hangup()\n";
        }

        return $config;
    }
}
