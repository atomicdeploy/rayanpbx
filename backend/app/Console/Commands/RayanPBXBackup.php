<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Extension;
use App\Models\Trunk;
use Illuminate\Support\Facades\File;
use Exception;

class RayanPBXBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:backup {--path=/opt/rayanpbx/backups} {--compress}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup RayanPBX configurations and database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $backupPath = $this->option('path');
        $compress = $this->option('compress');

        // Create backup directory if it doesn't exist
        if (!File::isDirectory($backupPath)) {
            File::makeDirectory($backupPath, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $backupDir = "{$backupPath}/backup_{$timestamp}";

        try {
            $this->info('Creating backup...');
            $this->newLine();

            // Create backup directory
            File::makeDirectory($backupDir, 0755, true);

            // Backup database
            $this->comment('Backing up database...');
            $this->backupDatabase($backupDir);
            $this->info('✓ Database backed up');

            // Backup .env file
            $this->comment('Backing up configuration...');
            $envFile = base_path('.env');
            if (File::exists($envFile)) {
                File::copy($envFile, "{$backupDir}/.env");
                $this->info('✓ .env file backed up');
            }

            // Backup Asterisk configurations
            $this->comment('Backing up Asterisk configurations...');
            $asteriskConfDir = "{$backupDir}/asterisk";
            File::makeDirectory($asteriskConfDir, 0755, true);
            
            $configFiles = [
                '/etc/asterisk/pjsip.conf',
                '/etc/asterisk/pjsip_custom.conf',
                '/etc/asterisk/extensions.conf',
                '/etc/asterisk/extensions_custom.conf',
                '/etc/asterisk/manager.conf',
            ];

            foreach ($configFiles as $configFile) {
                if (File::exists($configFile)) {
                    $filename = basename($configFile);
                    File::copy($configFile, "{$asteriskConfDir}/{$filename}");
                }
            }
            $this->info('✓ Asterisk configurations backed up');

            // Create backup metadata
            $metadata = [
                'timestamp' => $timestamp,
                'version' => config('app.version', '2.0.0'),
                'extensions_count' => Extension::count(),
                'trunks_count' => Trunk::count(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ];

            File::put(
                "{$backupDir}/metadata.json",
                json_encode($metadata, JSON_PRETTY_PRINT)
            );

            // Compress if requested
            if ($compress) {
                $this->newLine();
                $this->comment('Compressing backup...');
                $archiveName = "backup_{$timestamp}.tar.gz";
                $archivePath = "{$backupPath}/{$archiveName}";
                
                exec("tar -czf {$archivePath} -C {$backupPath} backup_{$timestamp}", $output, $returnCode);
                
                if ($returnCode === 0) {
                    File::deleteDirectory($backupDir);
                    $this->info("✓ Backup compressed to {$archiveName}");
                    $backupDir = $archivePath;
                } else {
                    $this->warn('⚠ Failed to compress backup, keeping uncompressed version');
                }
            }

            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════');
            $this->info('✓ Backup completed successfully');
            $this->info("  Location: {$backupDir}");
            $this->info('═══════════════════════════════════════════════════════');

            return 0;
        } catch (Exception $e) {
            $this->error('✗ Backup failed: ' . $e->getMessage());
            
            // Cleanup on failure
            if (File::isDirectory($backupDir)) {
                File::deleteDirectory($backupDir);
            }
            
            return 1;
        }
    }

    /**
     * Backup database
     */
    private function backupDatabase(string $backupDir): void
    {
        $dbConnection = config('database.default');
        $dbConfig = config("database.connections.{$dbConnection}");

        if ($dbConfig['driver'] === 'mysql') {
            $host = $dbConfig['host'];
            $database = $dbConfig['database'];
            $username = $dbConfig['username'];
            $password = $dbConfig['password'];

            $backupFile = "{$backupDir}/database.sql";
            
            // Create a temporary MySQL config file for secure authentication
            $tmpConfig = tempnam(sys_get_temp_dir(), 'mysql_');
            $configContent = "[client]\n";
            $configContent .= "user=" . $username . "\n";
            $configContent .= "password=" . $password . "\n";
            $configContent .= "host=" . $host . "\n";
            
            file_put_contents($tmpConfig, $configContent);
            chmod($tmpConfig, 0600);

            try {
                $command = sprintf(
                    'mysqldump --defaults-file=%s %s > %s 2>&1',
                    escapeshellarg($tmpConfig),
                    escapeshellarg($database),
                    escapeshellarg($backupFile)
                );

                exec($command, $output, $returnCode);

                if ($returnCode !== 0) {
                    throw new Exception('Database backup failed: ' . implode("\n", $output));
                }
            } finally {
                // Always remove the temporary config file
                if (file_exists($tmpConfig)) {
                    unlink($tmpConfig);
                }
            }
        } else {
            throw new Exception('Only MySQL database backups are currently supported');
        }
    }
}
