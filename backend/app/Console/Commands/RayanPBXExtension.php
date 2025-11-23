<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Extension;
use Illuminate\Support\Facades\Validator;
use Exception;

class RayanPBXExtension extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:extension {action} {extension?} {--name=} {--email=} {--secret=} {--context=default} {--all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage SIP extensions (list|create|delete|enable|disable|show)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $extensionNumber = $this->argument('extension');

        $validActions = ['list', 'create', 'delete', 'enable', 'disable', 'show'];

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
                    
                case 'show':
                    return $this->showExtension($extensionNumber);
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
        $this->newLine();
        $this->info('Please run the following command to apply the configuration:');
        $this->line('  php artisan rayanpbx:config reload');

        return 0;
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
