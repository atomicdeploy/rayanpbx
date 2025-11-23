<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Exception;

class RayanPBXRestore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:restore {backup} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore RayanPBX from backup';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $backupPath = $this->argument('backup');

        if (!File::exists($backupPath)) {
            $this->error("Backup not found: {$backupPath}");
            return 1;
        }

        // Check if it's a compressed backup
        $isCompressed = str_ends_with($backupPath, '.tar.gz');
        $tempDir = null;

        try {
            if ($isCompressed) {
                $this->comment('Extracting backup...');
                $tempDir = sys_get_temp_dir() . '/rayanpbx_restore_' . time();
                File::makeDirectory($tempDir, 0755, true);
                
                exec("tar -xzf {$backupPath} -C {$tempDir}", $output, $returnCode);
                
                if ($returnCode !== 0) {
                    throw new Exception('Failed to extract backup archive');
                }

                // Find the backup directory inside the extracted archive
                $dirs = File::directories($tempDir);
                if (empty($dirs)) {
                    throw new Exception('Invalid backup archive structure');
                }
                $backupDir = $dirs[0];
                $this->info('✓ Backup extracted');
            } else {
                $backupDir = $backupPath;
            }

            // Read metadata
            $metadataFile = "{$backupDir}/metadata.json";
            if (File::exists($metadataFile)) {
                $metadata = json_decode(File::get($metadataFile), true);
                
                $this->newLine();
                $this->info('Backup Information:');
                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Timestamp', $metadata['timestamp'] ?? 'Unknown'],
                        ['Version', $metadata['version'] ?? 'Unknown'],
                        ['Extensions', $metadata['extensions_count'] ?? 'Unknown'],
                        ['Trunks', $metadata['trunks_count'] ?? 'Unknown'],
                    ]
                );
                $this->newLine();
            }

            if (!$this->option('force')) {
                if (!$this->confirm('This will overwrite current configuration. Continue?', false)) {
                    $this->info('Cancelled');
                    return 0;
                }
            }

            $this->info('Restoring from backup...');
            $this->newLine();

            // Restore database
            $this->comment('Restoring database...');
            $this->restoreDatabase($backupDir);
            $this->info('✓ Database restored');

            // Restore .env file
            $envBackup = "{$backupDir}/.env";
            if (File::exists($envBackup)) {
                $this->comment('Restoring configuration...');
                File::copy($envBackup, base_path('.env'));
                $this->info('✓ .env file restored');
            }

            // Restore Asterisk configurations
            $this->comment('Restoring Asterisk configurations...');
            $asteriskBackupDir = "{$backupDir}/asterisk";
            
            if (File::isDirectory($asteriskBackupDir)) {
                $configFiles = File::files($asteriskBackupDir);
                
                foreach ($configFiles as $file) {
                    $filename = $file->getFilename();
                    $targetPath = "/etc/asterisk/{$filename}";
                    
                    // Check if we have permission to write
                    if (is_writable(dirname($targetPath))) {
                        File::copy($file->getPathname(), $targetPath);
                    } else {
                        $this->warn("  ⚠ Cannot write to {$targetPath} (permission denied)");
                    }
                }
                $this->info('✓ Asterisk configurations restored');
            }

            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════');
            $this->info('✓ Restore completed successfully');
            $this->info('═══════════════════════════════════════════════════════');
            $this->newLine();
            $this->warn('Important: You may need to reload services:');
            $this->line('  php artisan rayanpbx:service restart all');

            // Cleanup temp directory
            if ($tempDir && File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }

            return 0;
        } catch (Exception $e) {
            $this->error('✗ Restore failed: ' . $e->getMessage());
            
            // Cleanup temp directory on failure
            if ($tempDir && File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
            
            return 1;
        }
    }

    /**
     * Restore database
     */
    private function restoreDatabase(string $backupDir): void
    {
        $dbConnection = config('database.default');
        $dbConfig = config("database.connections.{$dbConnection}");

        if ($dbConfig['driver'] === 'mysql') {
            $host = $dbConfig['host'];
            $database = $dbConfig['database'];
            $username = $dbConfig['username'];
            $password = $dbConfig['password'];

            $backupFile = "{$backupDir}/database.sql";
            
            if (!File::exists($backupFile)) {
                throw new Exception('Database backup file not found');
            }

            // Use mysql with password via environment variable to avoid shell exposure
            $command = sprintf(
                'MYSQL_PWD=%s mysql -h %s -u %s %s < %s 2>&1',
                escapeshellarg($password),
                escapeshellarg($host),
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($backupFile)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception('Database restore failed: ' . implode("\n", $output));
            }
        } else {
            throw new Exception('Only MySQL database restores are currently supported');
        }
    }
}
