<?php

namespace App\Console\Commands;

use App\Models\Extension;
use App\Models\Trunk;
use App\Services\SystemctlService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class RayanPBXSystem extends Command
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
    protected $signature = 'rayanpbx:system 
        {action : Action to perform (set-mode|toggle-debug|reset|update|upgrade|version)}
        {--mode= : Mode for set-mode (production|development|local)}
        {--yes : Skip confirmation prompts}
        {--keep-database : For reset, keep database data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'System management commands for RayanPBX';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        $validActions = ['set-mode', 'toggle-debug', 'reset', 'update', 'upgrade', 'version'];

        if (! in_array($action, $validActions)) {
            $this->error("❌ Invalid action: {$action}");
            $this->info('Valid actions: '.implode(', ', $validActions));

            return 1;
        }

        try {
            switch ($action) {
                case 'set-mode':
                    return $this->setMode();
                case 'toggle-debug':
                    return $this->toggleDebug();
                case 'reset':
                    return $this->reset();
                case 'update':
                    return $this->update();
                case 'upgrade':
                    return $this->upgrade();
                case 'version':
                    return $this->showVersion();
            }
        } catch (Exception $e) {
            $this->error('❌ Error: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * Set application mode
     */
    private function setMode(): int
    {
        $mode = $this->option('mode');

        if (! $mode) {
            $mode = $this->choice('Select application mode', ['production', 'development', 'local'], 0);
        }

        $this->printHeader('⚙️ Setting Application Mode');

        $modeConfig = [
            'production' => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'],
            'prod' => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'],
            'development' => ['APP_ENV' => 'development', 'APP_DEBUG' => 'true'],
            'dev' => ['APP_ENV' => 'development', 'APP_DEBUG' => 'true'],
            'local' => ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'],
        ];

        if (! isset($modeConfig[$mode])) {
            $this->error("❌ Invalid mode: {$mode}");
            $this->info('Valid modes: production, development, local');

            return 1;
        }

        $config = $modeConfig[$mode];
        $envFile = base_path('.env');

        if (! file_exists($envFile)) {
            $this->error('❌ .env file not found');

            return 1;
        }

        $this->info("Setting mode to: {$config['APP_ENV']}");

        // Update .env file
        $envContent = file_get_contents($envFile);

        foreach ($config as $key => $value) {
            if (preg_match("/^{$key}=.*/m", $envContent)) {
                $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $envContent);
            } else {
                $envContent .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envFile, $envContent);

        $this->info('  ✅ APP_ENV set to: '.$config['APP_ENV']);
        $this->info('  ✅ APP_DEBUG set to: '.$config['APP_DEBUG']);

        // Clear config cache
        $this->newLine();
        $this->comment('Clearing configuration cache...');
        $this->call('config:clear');
        $this->call('cache:clear');

        $this->newLine();
        $this->info('✅ Application mode set successfully');

        if ($config['APP_ENV'] === 'production') {
            $this->newLine();
            $this->comment('💡 Production mode tips:');
            $this->line('  • Run: php artisan config:cache');
            $this->line('  • Run: php artisan route:cache');
            $this->line('  • Run: php artisan view:cache');
        }

        return 0;
    }

    /**
     * Toggle debug mode
     */
    private function toggleDebug(): int
    {
        $this->printHeader('🔧 Toggle Debug Mode');

        $envFile = base_path('.env');

        if (! file_exists($envFile)) {
            $this->error('❌ .env file not found');

            return 1;
        }

        $envContent = file_get_contents($envFile);

        // Get current debug state
        $currentDebug = 'false';
        if (preg_match('/^APP_DEBUG=(.*)$/m', $envContent, $matches)) {
            $currentDebug = strtolower(trim($matches[1]));
        }

        $newDebug = ($currentDebug === 'true') ? 'false' : 'true';

        // Update .env file
        if (preg_match('/^APP_DEBUG=.*/m', $envContent)) {
            $envContent = preg_replace('/^APP_DEBUG=.*/m', "APP_DEBUG={$newDebug}", $envContent);
        } else {
            $envContent .= "\nAPP_DEBUG={$newDebug}";
        }

        file_put_contents($envFile, $envContent);

        $this->info("Debug mode: {$currentDebug} → {$newDebug}");

        // Clear config cache
        $this->call('config:clear', ['--quiet' => true]);
        $this->call('cache:clear', ['--quiet' => true]);

        if ($newDebug === 'true') {
            $this->warn('⚠️  Debug mode is now ENABLED');
            $this->comment('   Do not use in production!');
        } else {
            $this->info('✅ Debug mode is now DISABLED');
        }

        return 0;
    }

    /**
     * Reset system configuration
     */
    private function reset(): int
    {
        $this->printHeader('🔄 Reset RayanPBX Configuration');

        $this->warn('⚠️  WARNING: This will reset ALL configuration!');
        $this->line('');
        $this->line('This will:');

        if (! $this->option('keep-database')) {
            $this->line('  • Delete all extensions from database');
            $this->line('  • Delete all trunks from database');
        } else {
            $this->info('  • Keep database data (--keep-database flag set)');
        }

        $this->line('  • Reset pjsip.conf to clean state');
        $this->line('  • Reset extensions.conf to clean state');
        $this->line('');

        if (! $this->option('yes')) {
            if (! $this->confirm('Are you sure you want to continue?', false)) {
                $this->info('Cancelled');

                return 0;
            }

            // Double confirmation for dangerous operation
            $confirmText = $this->ask('Type "RESET" to confirm');
            if ($confirmText !== 'RESET') {
                $this->info('Cancelled - confirmation text did not match');

                return 0;
            }
        }

        $this->newLine();
        $this->comment('Resetting configuration...');

        // Reset database
        if (! $this->option('keep-database')) {
            $this->info('Clearing database...');
            Extension::query()->delete();
            $this->info('  ✅ Extensions cleared');

            Trunk::query()->delete();
            $this->info('  ✅ Trunks cleared');
        }

        // Reset pjsip.conf
        $pjsipConf = '/etc/asterisk/pjsip.conf';
        if (file_exists($pjsipConf)) {
            $this->info('Resetting pjsip.conf...');

            // Backup first
            $backupFile = $pjsipConf.'.backup.'.date('YmdHis');
            copy($pjsipConf, $backupFile);

            $pjsipContent = <<<'EOF'
; RayanPBX PJSIP Configuration
; Reset to clean state by RayanPBX Reset Configuration

; UDP Transport (default)
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060
allow_reload=yes

; TCP Transport
[transport-tcp]
type=transport
protocol=tcp
bind=0.0.0.0:5060
allow_reload=yes

EOF;

            file_put_contents($pjsipConf, $pjsipContent);
            $this->info('  ✅ pjsip.conf reset to clean state');
        }

        // Reset extensions.conf
        $extensionsConf = '/etc/asterisk/extensions.conf';
        if (file_exists($extensionsConf)) {
            $this->info('Resetting extensions.conf...');

            // Backup first
            $backupFile = $extensionsConf.'.backup.'.date('YmdHis');
            copy($extensionsConf, $backupFile);

            $extensionsContent = <<<'EOF'
; RayanPBX Dialplan Configuration
; Reset to clean state by RayanPBX Reset Configuration

[general]
static=yes
writeprotect=no

[globals]

[from-internal]
; Add your extension dialplan rules here

EOF;

            file_put_contents($extensionsConf, $extensionsContent);
            $this->info('  ✅ extensions.conf reset to clean state');
        }

        // Reload Asterisk
        $this->newLine();
        $this->comment('Reloading Asterisk configuration...');
        try {
            $this->systemctl->execAsteriskCLI('module reload res_pjsip.so');
            $this->info('  ✅ PJSIP module reloaded');

            $this->systemctl->execAsteriskCLI('dialplan reload');
            $this->info('  ✅ Dialplan reloaded');
        } catch (Exception $e) {
            $this->warn('  ⚠️  Could not reload Asterisk: '.$e->getMessage());
        }

        $this->newLine();
        $this->info('✅ Reset completed successfully!');
        $this->line('');
        $this->line('Configuration has been reset to a clean state.');
        $this->line('You can now add new extensions and trunks.');

        return 0;
    }

    /**
     * Update from repository
     */
    private function update(): int
    {
        $this->printHeader('🚀 Updating RayanPBX');

        $rootDir = dirname(dirname(dirname(dirname(base_path()))));

        // Check if it's a git repository
        if (! is_dir($rootDir.'/.git')) {
            // Try parent directories
            $rootDir = dirname(base_path());
            if (! is_dir($rootDir.'/.git')) {
                $this->error('❌ Not a git repository');
                $this->info('Update is only available for git-based installations');

                return 1;
            }
        }

        $this->info("Repository root: {$rootDir}");

        // Pull latest changes
        $this->newLine();
        $this->comment('Pulling latest changes...');
        exec("cd {$rootDir} && git pull origin main 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            $this->error('❌ Failed to pull changes');
            $this->line(implode("\n", $output));

            return 1;
        }
        $this->info('  ✅ Git pull completed');

        // Update backend dependencies
        $this->newLine();
        $this->comment('Updating backend dependencies...');
        exec('cd '.base_path().' && composer install --no-dev 2>&1', $output, $returnCode);
        if ($returnCode === 0) {
            $this->info('  ✅ Backend dependencies updated');
        } else {
            $this->warn('  ⚠️  Backend dependencies update had issues');
        }

        $this->newLine();
        $this->info('✅ Update complete!');
        $this->warn('⚠️  Restart services to apply changes');
        $this->line('  php artisan rayanpbx:service restart all');

        return 0;
    }

    /**
     * Run upgrade script
     */
    private function upgrade(): int
    {
        $this->printHeader('🚀 Upgrading RayanPBX');

        $scriptsDir = dirname(dirname(dirname(dirname(base_path())))).'/scripts';
        $upgradeScript = $scriptsDir.'/upgrade.sh';

        if (! file_exists($upgradeScript)) {
            // Try alternative location
            $upgradeScript = '/opt/rayanpbx/scripts/upgrade.sh';
        }

        if (! file_exists($upgradeScript)) {
            $this->error('❌ Upgrade script not found');
            $this->info('Expected location: /opt/rayanpbx/scripts/upgrade.sh');

            return 1;
        }

        $this->info('Launching upgrade script...');
        $this->warn('Note: This may require sudo privileges');

        // Execute the upgrade script
        passthru("sudo bash {$upgradeScript}", $returnCode);

        return $returnCode;
    }

    /**
     * Show version information
     */
    private function showVersion(): int
    {
        $versionFile = dirname(dirname(dirname(dirname(base_path())))).'/VERSION';

        if (! file_exists($versionFile)) {
            $versionFile = '/opt/rayanpbx/VERSION';
        }

        $version = '2.0.0';
        if (file_exists($versionFile)) {
            $version = trim(file_get_contents($versionFile));
        }

        $this->line('');
        $this->info('╔═══════════════════════════════════════╗');
        $this->info('║        RayanPBX System Info           ║');
        $this->info('╠═══════════════════════════════════════╣');
        $this->line("║  Version:      {$version}                  ║");
        $this->line('║  PHP:          '.PHP_VERSION.str_repeat(' ', 24 - strlen(PHP_VERSION)).'║');
        $this->line('║  Laravel:      '.app()->version().str_repeat(' ', 24 - strlen(app()->version())).'║');
        $this->info('╚═══════════════════════════════════════╝');
        $this->line('');

        return 0;
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
}
