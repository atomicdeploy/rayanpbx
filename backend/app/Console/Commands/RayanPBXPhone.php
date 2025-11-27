<?php

namespace App\Console\Commands;

use App\Models\VoipPhone;
use App\Services\GrandStreamSessionService;
use Illuminate\Console\Command;

class RayanPBXPhone extends Command
{
    protected GrandStreamSessionService $grandstream;

    public function __construct(GrandStreamSessionService $grandstream)
    {
        parent::__construct();
        $this->grandstream = $grandstream;
    }

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'rayanpbx:phone
                            {action : Action to perform (info, sip, provision, sync, test)}
                            {--ip= : Phone IP address}
                            {--id= : Phone ID from database}
                            {--username=admin : Username for authentication}
                            {--password= : Password for authentication}
                            {--extension= : SIP extension for provisioning}
                            {--sip-password= : SIP password for provisioning}
                            {--sip-server= : SIP server for provisioning}
                            {--display-name= : Display name for provisioning}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Manage VoIP phones (GrandStream)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'info' => $this->showInfo(),
            'sip' => $this->showSipConfig(),
            'provision' => $this->provisionPhone(),
            'sync' => $this->syncPhone(),
            'test' => $this->testAuthentication(),
            default => $this->showHelp(),
        };
    }

    /**
     * Get the phone to work with
     */
    protected function getPhone(): ?VoipPhone
    {
        $id = $this->option('id');
        $ip = $this->option('ip');

        if ($id) {
            $phone = VoipPhone::find($id);
            if (! $phone) {
                $this->error("Phone with ID {$id} not found");

                return null;
            }

            return $phone;
        }

        if ($ip) {
            // Create a temporary phone object for direct IP access
            $phone = new VoipPhone([
                'ip' => $ip,
                'vendor' => 'grandstream',
            ]);
            $phone->id = 0; // Temporary ID

            return $phone;
        }

        $this->error('Please provide either --ip or --id');

        return null;
    }

    /**
     * Get credentials for the current operation
     */
    protected function getCredentials(): array
    {
        return [
            'username' => $this->option('username') ?? 'admin',
            'password' => $this->option('password'),
        ];
    }

    /**
     * Show device information
     */
    protected function showInfo(): int
    {
        $phone = $this->getPhone();
        if (! $phone) {
            return 1;
        }

        $credentials = $this->getCredentials();
        if (empty($credentials['password']) && ! $phone->hasCredentials()) {
            $this->error('Please provide --password for authentication');

            return 1;
        }

        $this->info('Fetching device information...');

        // Get or create session with provided credentials
        $sessionResult = $this->grandstream->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            $this->error('Failed to authenticate: '.($sessionResult['error'] ?? 'Unknown error'));

            return 1;
        }

        $result = $this->grandstream->getDeviceInfo($phone);

        if (! $result['success']) {
            $this->error('Failed: '.($result['error'] ?? 'Unknown error'));

            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return 0;
        }

        $info = $result['device_info'];

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('   Phone Information');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();

        $this->line("  <comment>Vendor:</comment>       {$info['vendor']}");
        $this->line("  <comment>Model:</comment>        {$info['model']}");
        $this->line("  <comment>Full Name:</comment>    {$info['vendor_fullname']}");

        if ($info['prog_version']) {
            $this->line("  <comment>Firmware:</comment>     {$info['prog_version']}");
        }
        if ($info['core_version']) {
            $this->line("  <comment>Core:</comment>         {$info['core_version']}");
        }
        if ($info['boot_version']) {
            $this->line("  <comment>Boot:</comment>         {$info['boot_version']}");
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');

        return 0;
    }

    /**
     * Show SIP account configuration
     */
    protected function showSipConfig(): int
    {
        $phone = $this->getPhone();
        if (! $phone) {
            return 1;
        }

        $credentials = $this->getCredentials();
        if (empty($credentials['password']) && ! $phone->hasCredentials()) {
            $this->error('Please provide --password for authentication');

            return 1;
        }

        $this->info('Fetching SIP account configuration...');

        // Get or create session with provided credentials
        $sessionResult = $this->grandstream->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            $this->error('Failed to authenticate: '.($sessionResult['error'] ?? 'Unknown error'));

            return 1;
        }

        $result = $this->grandstream->getSipAccount($phone);

        if (! $result['success']) {
            $this->error('Failed: '.($result['error'] ?? 'Unknown error'));

            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return 0;
        }

        $sip = $result['sip_account'];

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('   SIP Account Configuration');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();

        $status = $sip['account_active'] ? '<info>✓ Active</info>' : '<error>✗ Inactive</error>';
        $this->line("  <comment>Status:</comment>       {$status}");
        $this->line("  <comment>Account Name:</comment> {$sip['account_name']}");
        $this->line("  <comment>SIP Server:</comment>   {$sip['sip_server']}");
        $this->line("  <comment>SIP User ID:</comment>  {$sip['sip_user_id']}");
        $this->line("  <comment>Auth ID:</comment>      {$sip['auth_id']}");
        $this->line("  <comment>Display Name:</comment> {$sip['display_name']}");

        if ($sip['secondary_sip_server']) {
            $this->line("  <comment>Secondary:</comment>    {$sip['secondary_sip_server']}");
        }
        if ($sip['outbound_proxy']) {
            $this->line("  <comment>Outbound:</comment>     {$sip['outbound_proxy']}");
        }
        if ($sip['voicemail']) {
            $this->line("  <comment>Voicemail:</comment>    {$sip['voicemail']}");
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');

        return 0;
    }

    /**
     * Provision a SIP extension on the phone
     */
    protected function provisionPhone(): int
    {
        $phone = $this->getPhone();
        if (! $phone) {
            return 1;
        }

        $credentials = $this->getCredentials();
        if (empty($credentials['password']) && ! $phone->hasCredentials()) {
            $this->error('Please provide --password for authentication');

            return 1;
        }

        $extension = $this->option('extension');
        $sipPassword = $this->option('sip-password');
        $sipServer = $this->option('sip-server');
        $displayName = $this->option('display-name');

        if (! $extension || ! $sipPassword || ! $sipServer) {
            $this->error('Provisioning requires --extension, --sip-password, and --sip-server');

            return 1;
        }

        // Get or create session with provided credentials
        $sessionResult = $this->grandstream->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            $this->error('Failed to authenticate: '.($sessionResult['error'] ?? 'Unknown error'));

            return 1;
        }

        $this->info("Provisioning extension {$extension} on phone...");

        $result = $this->grandstream->provisionExtension(
            $phone,
            $extension,
            $sipPassword,
            $sipServer,
            $displayName
        );

        if (! $result['success']) {
            $this->error('Failed: '.($result['error'] ?? 'Unknown error'));

            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('═══════════════════════════════════════════════════════');
        $this->info('   Provisioning Complete');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();
        $this->line("  <info>✓</info> Extension <comment>{$extension}</comment> configured");
        $this->line("  <info>✓</info> SIP Server: <comment>{$sipServer}</comment>");
        $this->newLine();
        $this->warn('  Note: Phone may need to be rebooted to apply changes');
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');

        return 0;
    }

    /**
     * Sync phone information to database
     */
    protected function syncPhone(): int
    {
        $id = $this->option('id');
        if (! $id) {
            $this->error('Sync requires --id (database phone ID)');

            return 1;
        }

        $phone = VoipPhone::find($id);
        if (! $phone) {
            $this->error("Phone with ID {$id} not found");

            return 1;
        }

        $this->info('Syncing phone information...');

        $result = $this->grandstream->syncPhoneInfo($phone);

        if (! $result['success']) {
            $this->error('Failed: '.($result['error'] ?? 'Unknown error'));

            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('═══════════════════════════════════════════════════════');
        $this->info('   Phone Synchronized');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();
        $this->line('  <info>✓</info> Database updated with device information');
        $this->line("  <comment>Model:</comment>    {$result['device_info']['model']}");
        $this->line("  <comment>Vendor:</comment>   {$result['device_info']['vendor']}");
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');

        return 0;
    }

    /**
     * Test authentication with a phone
     */
    protected function testAuthentication(): int
    {
        $ip = $this->option('ip');
        $username = $this->option('username') ?? 'admin';
        $password = $this->option('password');

        if (! $ip || ! $password) {
            $this->error('Test requires --ip and --password');

            return 1;
        }

        $this->info("Testing authentication with {$ip}...");

        $result = $this->grandstream->testAuthentication($ip, $username, $password);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return $result['success'] ? 0 : 1;
        }

        if ($result['success']) {
            $this->info('═══════════════════════════════════════════════════════');
            $this->info('   Authentication Successful');
            $this->info('═══════════════════════════════════════════════════════');
            $this->newLine();
            $this->line('  <info>✓</info> Connected to phone');
            $this->line("  <comment>Session ID:</comment> {$result['sid']}");
            $this->line("  <comment>Role:</comment>       {$result['role']}");
            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════');

            return 0;
        }

        $this->error('Authentication failed: '.($result['error'] ?? 'Unknown error'));

        return 1;
    }

    /**
     * Show help for available actions
     */
    protected function showHelp(): int
    {
        $this->error('Unknown action. Available actions:');
        $this->newLine();
        $this->line('  <comment>info</comment>      - Show device information');
        $this->line('  <comment>sip</comment>       - Show SIP account configuration');
        $this->line('  <comment>provision</comment> - Configure SIP extension on phone');
        $this->line('  <comment>sync</comment>      - Sync phone info to database');
        $this->line('  <comment>test</comment>      - Test authentication');
        $this->newLine();
        $this->line('Examples:');
        $this->line('  php artisan rayanpbx:phone info --ip=192.168.1.100 --password=secret');
        $this->line('  php artisan rayanpbx:phone sip --id=1');
        $this->line('  php artisan rayanpbx:phone provision --ip=192.168.1.100 --password=secret \\');
        $this->line('      --extension=101 --sip-password=ext101 --sip-server=pbx.example.com');

        return 1;
    }
}
