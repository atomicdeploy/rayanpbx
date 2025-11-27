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
                            {action : Action to perform (list, info, sip, provision, sync, test)}
                            {--ip= : Phone IP address}
                            {--id= : Phone ID from database}
                            {--mac= : Phone MAC address}
                            {--ext= : Phone extension number}
                            {--username= : Username for authentication (default: use stored or admin)}
                            {--password= : Password for authentication (default: use stored credentials)}
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
            'list' => $this->listPhones(),
            'info' => $this->showInfo(),
            'sip' => $this->showSipConfig(),
            'provision' => $this->provisionPhone(),
            'sync' => $this->syncPhone(),
            'test' => $this->testAuthentication(),
            default => $this->showHelp(),
        };
    }

    /**
     * Get the phone to work with using multiple selectors
     */
    protected function getPhone(): ?VoipPhone
    {
        $id = $this->option('id');
        $ip = $this->option('ip');
        $mac = $this->option('mac');
        $ext = $this->option('ext');

        // Find by database ID
        if ($id) {
            $phone = VoipPhone::find($id);
            if (! $phone) {
                $this->error("Phone with ID {$id} not found");

                return null;
            }

            return $phone;
        }

        // Find by MAC address
        if ($mac) {
            $phone = VoipPhone::where('mac', strtolower($mac))
                ->orWhere('mac', strtoupper($mac))
                ->first();
            if (! $phone) {
                $this->error("Phone with MAC {$mac} not found");

                return null;
            }

            return $phone;
        }

        // Find by extension
        if ($ext) {
            $phone = VoipPhone::where('extension', $ext)->first();
            if (! $phone) {
                $this->error("Phone with extension {$ext} not found");

                return null;
            }

            return $phone;
        }

        // Find by IP address in database first
        if ($ip) {
            $phone = VoipPhone::where('ip', $ip)->first();
            if ($phone) {
                return $phone;
            }

            // Create a temporary phone object for direct IP access
            // Note: This is a transient object NOT for database operations
            $phone = new VoipPhone([
                'ip' => $ip,
                'vendor' => 'grandstream',
            ]);
            // Use negative ID to indicate this is a temporary object
            // This prevents accidental database operations
            $phone->id = -1;
            $phone->exists = false;

            return $phone;
        }

        $this->error('Please provide a selector: --ip, --id, --mac, or --ext');

        return null;
    }

    /**
     * Get credentials for the current operation.
     * Uses stored credentials from database if not provided.
     * Updates stored credentials if authentication succeeds with different creds.
     */
    protected function getCredentials(?VoipPhone $phone = null): array
    {
        $username = $this->option('username');
        $password = $this->option('password');

        // If phone exists and has stored credentials, use them as defaults
        if ($phone && $phone->hasCredentials()) {
            $stored = $phone->getCredentialsForApi();
            $username = $username ?? $stored['username'] ?? 'admin';
            $password = $password ?? $stored['password'] ?? null;
        } else {
            // Default username if not provided
            $username = $username ?? 'admin';
        }

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * Update stored credentials if they differ from what was used.
     */
    protected function updateStoredCredentials(VoipPhone $phone, array $credentials): void
    {
        // Skip if this is a temporary phone object (id = -1)
        if ($phone->id <= 0 || ! $phone->exists) {
            return;
        }

        $stored = $phone->getCredentialsForApi();

        // Check if credentials differ
        if (
            ($stored['username'] ?? 'admin') !== $credentials['username'] ||
            ($stored['password'] ?? '') !== $credentials['password']
        ) {
            $phone->credentials = $credentials;
            $phone->save();
            $this->info('Updated stored credentials for phone.');
        }
    }

    /**
     * List all phones in a table
     */
    protected function listPhones(): int
    {
        $phones = VoipPhone::orderBy('id')->get();

        if ($phones->isEmpty()) {
            $this->warn('No phones found in database.');

            return 0;
        }

        if ($this->option('json')) {
            $this->line(json_encode($phones->toArray(), JSON_PRETTY_PRINT));

            return 0;
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════════════════');
        $this->info('   VoIP Phones');
        $this->info('═══════════════════════════════════════════════════════════════════════════════');
        $this->newLine();

        $headers = ['ID', 'IP', 'MAC', 'Ext', 'Name', 'Vendor', 'Model', 'Status'];
        $rows = $phones->map(function ($phone) {
            return [
                $phone->id,
                $phone->ip,
                $phone->mac ?? '-',
                $phone->extension ?? '-',
                $phone->name ?? $phone->getDisplayName(),
                $phone->vendor ?? '-',
                $phone->model ?? '-',
                $phone->status ?? 'unknown',
            ];
        })->toArray();

        $this->table($headers, $rows);

        $this->newLine();
        $this->info("Total: {$phones->count()} phone(s)");
        $this->newLine();

        return 0;
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

        $credentials = $this->getCredentials($phone);
        if (empty($credentials['password'])) {
            $this->error('No password available. Please provide --password or ensure credentials are stored for this phone.');

            return 1;
        }

        $this->info('Fetching device information...');

        // Get or create session with provided credentials
        $sessionResult = $this->grandstream->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            $this->error('Failed to authenticate: '.($sessionResult['error'] ?? 'Unknown error'));

            return 1;
        }

        // Update stored credentials if authentication succeeded
        $this->updateStoredCredentials($phone, $credentials);

        $result = $this->grandstream->getDeviceInfo($phone, $credentials);

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

        $credentials = $this->getCredentials($phone);
        if (empty($credentials['password'])) {
            $this->error('No password available. Please provide --password or ensure credentials are stored for this phone.');

            return 1;
        }

        $this->info('Fetching SIP account configuration...');

        // Get or create session with provided credentials
        $sessionResult = $this->grandstream->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            $this->error('Failed to authenticate: '.($sessionResult['error'] ?? 'Unknown error'));

            return 1;
        }

        // Update stored credentials if authentication succeeded
        $this->updateStoredCredentials($phone, $credentials);

        $result = $this->grandstream->getSipAccount($phone, 1, $credentials);

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

        $credentials = $this->getCredentials($phone);
        if (empty($credentials['password'])) {
            $this->error('No password available. Please provide --password or ensure credentials are stored for this phone.');

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

        // Update stored credentials if authentication succeeded
        $this->updateStoredCredentials($phone, $credentials);

        $this->info("Provisioning extension {$extension} on phone...");

        $result = $this->grandstream->provisionExtension(
            $phone,
            $extension,
            $sipPassword,
            $sipServer,
            $displayName,
            1,
            $credentials
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
        $phone = $this->getPhone();
        if (! $phone) {
            return 1;
        }

        $credentials = $this->getCredentials($phone);
        if (empty($credentials['password'])) {
            $this->error('No password available. Please provide --password or ensure credentials are stored for this phone.');

            return 1;
        }

        $this->info("Testing authentication with {$phone->ip}...");

        $result = $this->grandstream->testAuthentication($phone->ip, $credentials['username'], $credentials['password']);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return $result['success'] ? 0 : 1;
        }

        if ($result['success']) {
            // Update stored credentials if authentication succeeded
            $this->updateStoredCredentials($phone, $credentials);

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
        $this->line('  <comment>list</comment>      - List all phones in database');
        $this->line('  <comment>info</comment>      - Show device information');
        $this->line('  <comment>sip</comment>       - Show SIP account configuration');
        $this->line('  <comment>provision</comment> - Configure SIP extension on phone');
        $this->line('  <comment>sync</comment>      - Sync phone info to database');
        $this->line('  <comment>test</comment>      - Test authentication');
        $this->newLine();
        $this->line('Selectors (use one):');
        $this->line('  --id=<id>     - Select by database ID');
        $this->line('  --ip=<ip>     - Select by IP address');
        $this->line('  --mac=<mac>   - Select by MAC address');
        $this->line('  --ext=<ext>   - Select by extension number');
        $this->newLine();
        $this->line('Authentication:');
        $this->line('  --username=<user>  - Username (default: stored or admin)');
        $this->line('  --password=<pass>  - Password (default: stored credentials)');
        $this->newLine();
        $this->line('Examples:');
        $this->line('  rayanpbx-cli phone list');
        $this->line('  rayanpbx-cli phone info --id=1');
        $this->line('  rayanpbx-cli phone info --ip=192.168.1.100 --password=secret');
        $this->line('  rayanpbx-cli phone sip --mac=00:0b:82:xx:xx:xx');
        $this->line('  rayanpbx-cli phone test --ext=101');
        $this->line('  rayanpbx-cli phone provision --id=1 --extension=101 \\');
        $this->line('      --sip-password=ext101 --sip-server=pbx.example.com');

        return 1;
    }
}
