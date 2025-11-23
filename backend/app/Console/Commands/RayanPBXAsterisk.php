<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemctlService;
use Exception;

class RayanPBXAsterisk extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:asterisk {command?} {--cli : Execute as Asterisk CLI command}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Asterisk CLI commands and manage Asterisk';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $command = $this->argument('command');
        $systemctl = new SystemctlService();

        if (!$command) {
            // Show common commands menu
            $choice = $this->choice(
                'Select an action',
                [
                    'Show active calls',
                    'Show channels',
                    'Show peers',
                    'Show endpoints',
                    'Reload configuration',
                    'Show version',
                    'Show uptime',
                    'Custom CLI command',
                ],
                0
            );

            switch ($choice) {
                case 'Show active calls':
                    $command = 'core show calls';
                    break;
                case 'Show channels':
                    $command = 'core show channels';
                    break;
                case 'Show peers':
                    $command = 'pjsip show endpoints';
                    break;
                case 'Show endpoints':
                    $command = 'pjsip show endpoints';
                    break;
                case 'Reload configuration':
                    $command = 'core reload';
                    break;
                case 'Show version':
                    $command = 'core show version';
                    break;
                case 'Show uptime':
                    $command = 'core show uptime';
                    break;
                case 'Custom CLI command':
                    $command = $this->ask('Enter Asterisk CLI command');
                    break;
            }
        }

        if (!$command) {
            $this->error('No command specified');
            return 1;
        }

        try {
            $this->info("Executing: asterisk -rx \"{$command}\"");
            $this->newLine();
            
            $output = $systemctl->execAsteriskCLI($command);
            
            if (empty($output)) {
                $this->warn('Command executed but returned no output');
            } else {
                $this->line($output);
            }

            return 0;
        } catch (Exception $e) {
            $this->error('Failed to execute command: ' . $e->getMessage());
            return 1;
        }
    }
}
