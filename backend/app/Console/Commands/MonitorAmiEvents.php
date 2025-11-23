<?php

namespace App\Console\Commands;

use App\Services\AmiEventMonitor;
use Illuminate\Console\Command;

class MonitorAmiEvents extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'rayanpbx:monitor-events
                            {--daemon : Run as a daemon in the background}';

    /**
     * The console command description.
     */
    protected $description = 'Monitor Asterisk AMI events for real-time notifications';

    /**
     * Execute the console command.
     */
    public function handle(AmiEventMonitor $monitor)
    {
        $this->info('Starting AMI Event Monitor...');
        $this->info('Monitoring for PJSIP registrations, calls, and other events');
        $this->info('Press Ctrl+C to stop');
        $this->line('');
        
        // Set up event callbacks for console output
        $monitor->on('ContactStatus', function($event) {
            $aor = $event['AOR'] ?? 'unknown';
            $status = $event['ContactStatus'] ?? 'unknown';
            
            if (preg_match('/^(\d+)$/', $aor, $matches)) {
                $extension = $matches[1];
                
                if ($status === 'Created' || $status === 'Reachable') {
                    $this->info("✅ Extension {$extension} registered");
                } elseif ($status === 'Removed' || $status === 'Unreachable') {
                    $this->warn("❌ Extension {$extension} unregistered");
                }
            }
        });
        
        $monitor->on('Newstate', function($event) {
            $channelStateDesc = $event['ChannelStateDesc'] ?? '';
            $callerIdNum = $event['CallerIDNum'] ?? '';
            $exten = $event['Exten'] ?? '';
            
            if ($channelStateDesc === 'Ringing' || $channelStateDesc === 'Ring') {
                $this->info("🔔 Extension {$exten} ringing from {$callerIdNum}");
            }
        });
        
        $monitor->on('Hangup', function($event) {
            $channel = $event['Channel'] ?? '';
            $causeText = $event['Cause-txt'] ?? 'Unknown';
            
            $this->line("📞 Call ended: {$channel} - {$causeText}");
        });
        
        // Handle graceful shutdown
        pcntl_signal(SIGINT, function() use ($monitor) {
            $this->line('');
            $this->warn('Shutting down gracefully...');
            $monitor->stop();
        });
        
        pcntl_signal(SIGTERM, function() use ($monitor) {
            $this->line('');
            $this->warn('Shutting down gracefully...');
            $monitor->stop();
        });
        
        // Start monitoring
        try {
            $success = $monitor->start();
            
            if ($success) {
                $this->info('AMI Event Monitor stopped successfully');
                return 0;
            } else {
                $this->error('Failed to start AMI Event Monitor');
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}
