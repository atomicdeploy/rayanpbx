<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Extension;
use App\Adapters\AsteriskAdapter;
use App\Services\PjsipService;
use Illuminate\Support\Facades\Validator;
use Exception;

class RayanPBXExtension extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:extension {action} {extension?} {--name=} {--email=} {--secret=} {--context=default} {--all} {--apply}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage SIP extensions (list|create|delete|enable|disable|toggle|show|verify|diagnose)';

    private $asterisk;
    private $pjsip;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->asterisk = app(AsteriskAdapter::class);
        $this->pjsip = app(PjsipService::class);
        
        $action = $this->argument('action');
        $extensionNumber = $this->argument('extension');

        $validActions = ['list', 'create', 'delete', 'enable', 'disable', 'toggle', 'show', 'verify', 'diagnose'];

        if (!in_array($action, $validActions)) {
            $this->error("Invalid action: {$action}");
            $this->info("Valid actions: " . implode(', ', $validActions));
            return 1;
        }

        try {
            switch ($action) {
                case 'list':
                    return $this->listExtensions();
                    
                case 'create':
                    return $this->createExtension();
                    
                case 'delete':
                    return $this->deleteExtension($extensionNumber);
                    
                case 'enable':
                    return $this->toggleExtension($extensionNumber, true);
                    
                case 'disable':
                    return $this->toggleExtension($extensionNumber, false);
                    
                case 'toggle':
                    return $this->toggleExtensionAuto($extensionNumber);
                    
                case 'show':
                    return $this->showExtension($extensionNumber);
                    
                case 'verify':
                    return $this->verifyExtension($extensionNumber);
                    
                case 'diagnose':
                    return $this->diagnoseExtension($extensionNumber);
            }
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * List all extensions
     */
    private function listExtensions(): int
    {
        $extensions = Extension::orderBy('extension_number')->get();

        if ($extensions->isEmpty()) {
            $this->warn('No extensions found');
            return 0;
        }

        $data = $extensions->map(function ($ext) {
            return [
                'extension_number' => $ext->extension_number,
                'name' => $ext->name,
                'email' => $ext->email ?: 'N/A',
                'enabled' => $ext->enabled ? '✓' : '✗',
                'context' => $ext->context,
                'status' => $ext->status,
            ];
        })->toArray();

        $this->table(
            ['Extension', 'Name', 'Email', 'Enabled', 'Context', 'Status'],
            $data
        );

        $this->newLine();
        $this->info('Total extensions: ' . $extensions->count());

        return 0;
    }

    /**
     * Create a new extension
     */
    private function createExtension(): int
    {
        $extensionNumber = $this->argument('extension') ?: $this->ask('Extension number');
        
        // Validate extension number
        $validator = Validator::make(['extension_number' => $extensionNumber], [
            'extension_number' => 'required|numeric|digits_between:2,10|unique:extensions,extension_number',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->line('  - ' . $error);
            }
            return 1;
        }

        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email (optional)', '');
        $secret = $this->option('secret') ?: $this->secret('Secret (password)');
        $context = $this->option('context') ?: 'default';

        // Validate inputs
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'secret' => $secret,
        ], [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'secret' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->line('  - ' . $error);
            }
            return 1;
        }

        $extension = Extension::create([
            'extension_number' => $extensionNumber,
            'name' => $name,
            'email' => $email ?: null,
            'secret' => $secret,
            'enabled' => true,
            'context' => $context,
            'transport' => 'transport-udp',
            'codecs' => ['ulaw', 'alaw', 'g722'],
            'max_contacts' => 1,
            'voicemail_enabled' => false,
        ]);

        $this->info("✓ Extension {$extensionNumber} created successfully");
        $this->newLine();
        $this->info('Please run the following command to apply the configuration:');
        $this->line('  php artisan rayanpbx:config reload');

        return 0;
    }

    /**
     * Delete an extension
     */
    private function deleteExtension(?string $extensionNumber): int
    {
        if (!$extensionNumber) {
            $extensionNumber = $this->ask('Extension number to delete');
        }

        $extension = Extension::where('extension_number', $extensionNumber)->first();

        if (!$extension) {
            $this->error("Extension {$extensionNumber} not found");
            return 1;
        }

        if (!$this->option('all')) {
            if (!$this->confirm("Are you sure you want to delete extension {$extensionNumber}?", false)) {
                $this->info('Cancelled');
                return 0;
            }
        }

        $extension->delete();
        $this->info("✓ Extension {$extensionNumber} deleted successfully");
        $this->newLine();
        $this->info('Please run the following command to apply the configuration:');
        $this->line('  php artisan rayanpbx:config reload');

        return 0;
    }

    /**
     * Enable or disable an extension
     */
    private function toggleExtension(?string $extensionNumber, bool $enable): int
    {
        if (!$extensionNumber) {
            $action = $enable ? 'enable' : 'disable';
            $extensionNumber = $this->ask("Extension number to {$action}");
        }

        $extension = Extension::where('extension_number', $extensionNumber)->first();

        if (!$extension) {
            $this->error("Extension {$extensionNumber} not found");
            return 1;
        }

        $extension->enabled = $enable;
        $extension->save();

        $action = $enable ? 'enabled' : 'disabled';
        $this->info("✓ Extension {$extensionNumber} {$action} successfully");
        
        // Auto-apply Asterisk configuration
        return $this->applyAsteriskConfig($extension);
    }
    
    /**
     * Toggle extension (flip current enabled state)
     */
    private function toggleExtensionAuto(?string $extensionNumber): int
    {
        if (!$extensionNumber) {
            $extensionNumber = $this->ask("Extension number to toggle");
        }

        $extension = Extension::where('extension_number', $extensionNumber)->first();

        if (!$extension) {
            $this->error("Extension {$extensionNumber} not found");
            return 1;
        }

        $newState = !$extension->enabled;
        $extension->enabled = $newState;
        $extension->save();

        $action = $newState ? 'enabled' : 'disabled';
        $this->info("✓ Extension {$extensionNumber} toggled to {$action}");
        
        // Auto-apply Asterisk configuration
        return $this->applyAsteriskConfig($extension);
    }
    
    /**
     * Apply Asterisk configuration for an extension
     */
    private function applyAsteriskConfig(Extension $extension): int
    {
        $this->newLine();
        $this->info('Applying Asterisk configuration...');
        
        try {
            if ($extension->enabled) {
                // Generate and write PJSIP config
                $this->asterisk->ensureTransportConfig();
                $config = $this->asterisk->generatePjsipEndpoint($extension);
                $success = $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
                
                if (!$success) {
                    $this->warn('  ⚠ Failed to write PJSIP configuration');
                }
            } else {
                // Comment out PJSIP config (preserve it but disable)
                $success = $this->asterisk->commentOutPjsipConfig("Extension {$extension->extension_number}");
                
                if (!$success) {
                    $this->warn('  ⚠ Failed to disable PJSIP configuration');
                }
            }
            
            // Regenerate dialplan for all enabled extensions
            $allExtensions = Extension::where('enabled', true)->get();
            $dialplanConfig = $this->asterisk->generateInternalDialplan($allExtensions);
            $dialplanSuccess = $this->asterisk->writeDialplanConfig($dialplanConfig, "RayanPBX Internal Extensions");
            
            if (!$dialplanSuccess) {
                $this->warn('  ⚠ Failed to write dialplan configuration');
            }
            
            // Reload Asterisk
            $reloadSuccess = $this->asterisk->reload();
            
            if ($reloadSuccess) {
                $this->info('  ✓ Asterisk configuration applied successfully');
            } else {
                $this->warn('  ⚠ Failed to reload Asterisk - try manually: asterisk -rx "pjsip reload"');
            }
            
            // Verify endpoint was created in Asterisk
            $verified = $this->asterisk->verifyEndpointExists($extension->extension_number);
            
            if ($extension->enabled) {
                if ($verified) {
                    $this->info('  ✓ Extension verified in Asterisk');
                } else {
                    $this->warn('  ⚠ Extension not found in Asterisk - may need manual verification');
                }
            }
            
            return 0;
        } catch (Exception $e) {
            $this->error('  ✗ Error applying configuration: ' . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Verify extension in Asterisk
     */
    private function verifyExtension(?string $extensionNumber): int
    {
        if (!$extensionNumber) {
            $extensionNumber = $this->ask('Extension number to verify');
        }

        $extension = Extension::where('extension_number', $extensionNumber)->first();

        if (!$extension) {
            $this->error("Extension {$extensionNumber} not found in database");
            return 1;
        }

        $this->info("Verifying Extension: {$extensionNumber}");
        $this->newLine();
        
        // Check database status
        $this->line("📦 Database Status:");
        $this->line("   Enabled: " . ($extension->enabled ? '✓ Yes' : '✗ No'));
        $this->line("   Name: {$extension->name}");
        $this->line("   Context: {$extension->context}");
        
        $this->newLine();
        
        // Check Asterisk endpoint
        $this->line("🔧 Asterisk Status:");
        $endpointDetails = $this->asterisk->getPjsipEndpoint($extensionNumber);
        
        if ($endpointDetails !== null) {
            $this->line("   Endpoint exists: ✓ Yes");
            $this->line("   State: " . ($endpointDetails['state'] ?? 'Unknown'));
            
            if (!empty($endpointDetails['contacts'])) {
                $this->line("   Contacts: " . count($endpointDetails['contacts']));
                foreach ($endpointDetails['contacts'] as $contact) {
                    $status = $contact['status'] ?? 'Unknown';
                    $uri = $contact['uri'] ?? 'N/A';
                    $icon = $status === 'Available' ? '🟢' : '⚫';
                    $this->line("     {$icon} {$uri} ({$status})");
                }
            } else {
                $this->line("   Contacts: 0 (not registered)");
            }
        } else {
            $this->warn("   Endpoint exists: ✗ No");
            
            if ($extension->enabled) {
                $this->warn("   ⚠ Extension is enabled but not in Asterisk!");
                $this->line("   Run: php artisan rayanpbx:sync db-to-asterisk {$extensionNumber}");
            }
        }
        
        // Check registration status
        $registrationStatus = $this->asterisk->getEndpointRegistrationStatus($extensionNumber);
        
        $this->newLine();
        $this->line("📞 Registration Status:");
        if ($registrationStatus['registered']) {
            $this->line("   Registered: 🟢 Yes");
        } else {
            $this->line("   Registered: ⚫ No");
        }
        
        return 0;
    }
    
    /**
     * Diagnose extension issues
     */
    private function diagnoseExtension(?string $extensionNumber): int
    {
        if (!$extensionNumber) {
            $extensionNumber = $this->ask('Extension number to diagnose');
        }

        $extension = Extension::where('extension_number', $extensionNumber)->first();

        $this->info("🔍 Diagnosing Extension: {$extensionNumber}");
        $this->newLine();
        
        $issues = [];
        $tips = [];
        
        // Check database
        $this->line("📦 Database Check:");
        if (!$extension) {
            $this->warn("   ✗ Extension not found in database");
            $issues[] = "Extension not in database";
            
            // Check if it exists in Asterisk
            $endpointDetails = $this->asterisk->getPjsipEndpoint($extensionNumber);
            if ($endpointDetails !== null) {
                $tips[] = "Extension exists in Asterisk but not in database. Run: php artisan rayanpbx:sync asterisk-to-db {$extensionNumber}";
            }
        } else {
            $this->line("   ✓ Found in database");
            $this->line("   Enabled: " . ($extension->enabled ? '✓' : '✗'));
            
            if (!$extension->enabled) {
                $issues[] = "Extension is disabled in database";
                $tips[] = "Enable the extension: php artisan rayanpbx:extension enable {$extensionNumber}";
            }
        }
        
        $this->newLine();
        
        // Check Asterisk configuration
        $this->line("🔧 Asterisk Configuration Check:");
        $endpointDetails = $this->asterisk->getPjsipEndpoint($extensionNumber);
        
        if ($endpointDetails !== null) {
            $this->line("   ✓ Endpoint configured in Asterisk");
        } else {
            $this->warn("   ✗ Endpoint not found in Asterisk");
            $issues[] = "Endpoint not configured in Asterisk";
            
            if ($extension && $extension->enabled) {
                $tips[] = "Sync extension to Asterisk: php artisan rayanpbx:sync db-to-asterisk {$extensionNumber}";
            }
        }
        
        // Check registration
        $this->newLine();
        $this->line("📞 Registration Check:");
        $registrationStatus = $this->pjsip->validateExtensionRegistration($extensionNumber);
        
        if ($registrationStatus['registered']) {
            $this->line("   ✓ Extension is registered");
            if ($registrationStatus['ip_address']) {
                $this->line("   IP: {$registrationStatus['ip_address']}:{$registrationStatus['port']}");
            }
            if ($registrationStatus['user_agent']) {
                $this->line("   User Agent: {$registrationStatus['user_agent']}");
            }
        } else {
            $this->warn("   ✗ Extension is not registered");
            $issues[] = "No SIP client registered";
            
            if (!empty($registrationStatus['errors'])) {
                foreach ($registrationStatus['errors'] as $error) {
                    $this->warn("     - {$error}");
                }
            }
            
            $tips[] = "Configure a SIP client with the extension credentials";
            $tips[] = "Check firewall settings for SIP port 5060";
        }
        
        // Summary
        $this->newLine();
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        
        if (empty($issues)) {
            $this->info("✅ No issues found - Extension appears healthy");
        } else {
            $this->warn("⚠️  Issues Found: " . count($issues));
            foreach ($issues as $issue) {
                $this->line("   - {$issue}");
            }
            
            if (!empty($tips)) {
                $this->newLine();
                $this->info("💡 Suggested Actions:");
                foreach ($tips as $tip) {
                    $this->line("   - {$tip}");
                }
            }
        }
        
        return empty($issues) ? 0 : 1;
    }

    /**
     * Show extension details
     */
    private function showExtension(?string $extensionNumber): int
    {
        if (!$extensionNumber) {
            $extensionNumber = $this->ask('Extension number');
        }

        $extension = Extension::where('extension_number', $extensionNumber)->first();

        if (!$extension) {
            $this->error("Extension {$extensionNumber} not found");
            return 1;
        }

        $this->info("Extension Details: {$extensionNumber}");
        $this->newLine();

        $this->table(
            ['Property', 'Value'],
            [
                ['Extension Number', $extension->extension_number],
                ['Name', $extension->name],
                ['Email', $extension->email ?: 'N/A'],
                ['Enabled', $extension->enabled ? 'Yes' : 'No'],
                ['Context', $extension->context],
                ['Transport', $extension->transport],
                ['Codecs', implode(', ', $extension->codecs ?? [])],
                ['Max Contacts', $extension->max_contacts],
                ['Caller ID', $extension->caller_id ?: 'N/A'],
                ['Voicemail', $extension->voicemail_enabled ? 'Enabled' : 'Disabled'],
                ['Status', $extension->status],
                ['Created', $extension->created_at],
                ['Updated', $extension->updated_at],
            ]
        );

        if ($extension->notes) {
            $this->newLine();
            $this->info('Notes:');
            $this->line($extension->notes);
        }

        return 0;
    }
}
