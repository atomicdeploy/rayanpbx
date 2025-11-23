<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Trunk;
use Illuminate\Support\Facades\Validator;
use Exception;

class RayanPBXTrunk extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:trunk {action} {trunk?} {--name=} {--type=} {--host=} {--username=} {--secret=} {--all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage SIP trunks (list|create|delete|enable|disable|show)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $trunkName = $this->argument('trunk');

        $validActions = ['list', 'create', 'delete', 'enable', 'disable', 'show'];

        if (!in_array($action, $validActions)) {
            $this->error("Invalid action: {$action}");
            $this->info("Valid actions: " . implode(', ', $validActions));
            return 1;
        }

        try {
            switch ($action) {
                case 'list':
                    return $this->listTrunks();
                    
                case 'create':
                    return $this->createTrunk();
                    
                case 'delete':
                    return $this->deleteTrunk($trunkName);
                    
                case 'enable':
                    return $this->toggleTrunk($trunkName, true);
                    
                case 'disable':
                    return $this->toggleTrunk($trunkName, false);
                    
                case 'show':
                    return $this->showTrunk($trunkName);
            }
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * List all trunks
     */
    private function listTrunks(): int
    {
        $trunks = Trunk::orderBy('priority', 'desc')->get();

        if ($trunks->isEmpty()) {
            $this->warn('No trunks found');
            return 0;
        }

        $data = $trunks->map(function ($trunk) {
            return [
                'name' => $trunk->name,
                'type' => $trunk->type,
                'host' => $trunk->host,
                'username' => $trunk->username ?: 'N/A',
                'enabled' => $trunk->enabled ? '✓' : '✗',
                'priority' => $trunk->priority,
                'status' => $trunk->status,
            ];
        })->toArray();

        $this->table(
            ['Name', 'Type', 'Host', 'Username', 'Enabled', 'Priority', 'Status'],
            $data
        );

        $this->newLine();
        $this->info('Total trunks: ' . $trunks->count());

        return 0;
    }

    /**
     * Create a new trunk
     */
    private function createTrunk(): int
    {
        $name = $this->option('name') ?: $this->ask('Trunk name');
        
        // Validate trunk name
        $validator = Validator::make(['name' => $name], [
            'name' => 'required|string|max:255|unique:trunks,name',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->line('  - ' . $error);
            }
            return 1;
        }

        $type = $this->option('type') ?: $this->choice('Trunk type', ['sip', 'iax2', 'pjsip'], 2);
        $host = $this->option('host') ?: $this->ask('SIP host/IP');
        $username = $this->option('username') ?: $this->ask('Username (optional)', '');
        $secret = $this->option('secret') ?: $this->secret('Secret/Password (optional)');

        // Validate inputs
        $validator = Validator::make([
            'type' => $type,
            'host' => $host,
        ], [
            'type' => 'required|in:sip,iax2,pjsip',
            'host' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->line('  - ' . $error);
            }
            return 1;
        }

        $trunk = Trunk::create([
            'name' => $name,
            'type' => $type,
            'host' => $host,
            'port' => 5060,
            'username' => $username ?: null,
            'secret' => $secret ?: null,
            'enabled' => true,
            'transport' => 'udp',
            'codecs' => ['ulaw', 'alaw', 'g722'],
            'context' => 'from-trunk',
            'priority' => 10,
            'max_channels' => 10,
        ]);

        $this->info("✓ Trunk {$name} created successfully");
        $this->newLine();
        $this->info('Please run the following command to apply the configuration:');
        $this->line('  php artisan rayanpbx:config reload');

        return 0;
    }

    /**
     * Delete a trunk
     */
    private function deleteTrunk(?string $trunkName): int
    {
        if (!$trunkName) {
            $trunkName = $this->ask('Trunk name to delete');
        }

        $trunk = Trunk::where('name', $trunkName)->first();

        if (!$trunk) {
            $this->error("Trunk {$trunkName} not found");
            return 1;
        }

        if (!$this->option('all')) {
            if (!$this->confirm("Are you sure you want to delete trunk {$trunkName}?", false)) {
                $this->info('Cancelled');
                return 0;
            }
        }

        $trunk->delete();
        $this->info("✓ Trunk {$trunkName} deleted successfully");
        $this->newLine();
        $this->info('Please run the following command to apply the configuration:');
        $this->line('  php artisan rayanpbx:config reload');

        return 0;
    }

    /**
     * Enable or disable a trunk
     */
    private function toggleTrunk(?string $trunkName, bool $enable): int
    {
        if (!$trunkName) {
            $action = $enable ? 'enable' : 'disable';
            $trunkName = $this->ask("Trunk name to {$action}");
        }

        $trunk = Trunk::where('name', $trunkName)->first();

        if (!$trunk) {
            $this->error("Trunk {$trunkName} not found");
            return 1;
        }

        $trunk->enabled = $enable;
        $trunk->save();

        $action = $enable ? 'enabled' : 'disabled';
        $this->info("✓ Trunk {$trunkName} {$action} successfully");
        $this->newLine();
        $this->info('Please run the following command to apply the configuration:');
        $this->line('  php artisan rayanpbx:config reload');

        return 0;
    }

    /**
     * Show trunk details
     */
    private function showTrunk(?string $trunkName): int
    {
        if (!$trunkName) {
            $trunkName = $this->ask('Trunk name');
        }

        $trunk = Trunk::where('name', $trunkName)->first();

        if (!$trunk) {
            $this->error("Trunk {$trunkName} not found");
            return 1;
        }

        $this->info("Trunk Details: {$trunkName}");
        $this->newLine();

        $this->table(
            ['Property', 'Value'],
            [
                ['Name', $trunk->name],
                ['Type', $trunk->type],
                ['Host', $trunk->host],
                ['Port', $trunk->port],
                ['Username', $trunk->username ?: 'N/A'],
                ['Enabled', $trunk->enabled ? 'Yes' : 'No'],
                ['Transport', $trunk->transport],
                ['Codecs', implode(', ', $trunk->codecs ?? [])],
                ['Context', $trunk->context],
                ['Priority', $trunk->priority],
                ['Prefix', $trunk->prefix ?: 'N/A'],
                ['Strip Digits', $trunk->strip_digits ?: 0],
                ['Max Channels', $trunk->max_channels],
                ['Status', $trunk->status],
                ['Created', $trunk->created_at],
                ['Updated', $trunk->updated_at],
            ]
        );

        if ($trunk->notes) {
            $this->newLine();
            $this->info('Notes:');
            $this->line($trunk->notes);
        }

        return 0;
    }
}
