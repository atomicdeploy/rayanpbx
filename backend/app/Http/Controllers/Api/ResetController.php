<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Models\Trunk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ResetController extends Controller
{
    private $pjsipPath = '/etc/asterisk/pjsip.conf';
    private $extensionsPath = '/etc/asterisk/extensions.conf';

    /**
     * Get reset summary - shows what will be deleted
     */
    public function summary()
    {
        try {
            $summary = [
                'database' => [
                    'extensions' => Extension::count(),
                    'trunks' => Trunk::count(),
                    'voip_phones' => 0,
                ],
                'asterisk_files' => [
                    'pjsip_conf' => File::exists($this->pjsipPath),
                    'extensions_conf' => File::exists($this->extensionsPath),
                ],
            ];

            // Try to count voip_phones (table may not exist)
            try {
                $summary['database']['voip_phones'] = DB::table('voip_phones')->count();
            } catch (\Exception $e) {
                // Table doesn't exist
            }

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'warning' => 'This action will reset ALL configuration and cannot be undone!'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get reset summary: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get reset summary: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset all configuration
     * This is a destructive operation that:
     * 1. Clears the database tables (extensions, trunks, voip_phones)
     * 2. Resets pjsip.conf to a clean state
     * 3. Resets extensions.conf to a clean state
     * 4. Reloads Asterisk configuration
     */
    public function reset(Request $request)
    {
        // Require confirmation token
        $request->validate([
            'confirm' => 'required|string|in:RESET',
        ], [
            'confirm.required' => 'Confirmation is required',
            'confirm.in' => 'You must send confirm: "RESET" to proceed',
        ]);

        $results = [
            'database_cleared' => false,
            'pjsip_cleared' => false,
            'extensions_cleared' => false,
            'asterisk_reloaded' => false,
            'extensions_removed' => 0,
            'trunks_removed' => 0,
            'voip_phones_removed' => 0,
            'errors' => [],
        ];

        try {
            // Step 1: Clear database tables
            $this->clearDatabase($results);

            // Step 2: Reset pjsip.conf
            $this->clearPjsipConfig($results);

            // Step 3: Reset extensions.conf
            $this->clearExtensionsConfig($results);

            // Step 4: Reload Asterisk
            $this->reloadAsterisk($results);

            Log::warning('System reset performed', $results);

            $success = empty($results['errors']);

            return response()->json([
                'success' => $success,
                'message' => $success 
                    ? 'Configuration reset completed successfully' 
                    : 'Configuration reset completed with errors',
                'results' => $results
            ], $success ? 200 : 207);

        } catch (\Exception $e) {
            Log::error('Failed to reset configuration: ' . $e->getMessage());
            $results['errors'][] = 'System error: ' . $e->getMessage();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset configuration: ' . $e->getMessage(),
                'results' => $results
            ], 500);
        }
    }

    /**
     * Clear database tables
     */
    private function clearDatabase(&$results)
    {
        try {
            // Count and delete extensions
            $results['extensions_removed'] = Extension::count();
            Extension::query()->delete();

            // Count and delete trunks
            $results['trunks_removed'] = Trunk::count();
            Trunk::query()->delete();

            // Try to delete voip_phones (table may not exist)
            try {
                $results['voip_phones_removed'] = DB::table('voip_phones')->count();
                DB::table('voip_phones')->delete();
            } catch (\Exception $e) {
                // Table doesn't exist, ignore
            }

            $results['database_cleared'] = true;
            Log::info('Database tables cleared', [
                'extensions' => $results['extensions_removed'],
                'trunks' => $results['trunks_removed'],
                'voip_phones' => $results['voip_phones_removed'],
            ]);

        } catch (\Exception $e) {
            $results['errors'][] = 'Database: ' . $e->getMessage();
            Log::error('Failed to clear database: ' . $e->getMessage());
        }
    }

    /**
     * Reset pjsip.conf to clean state
     */
    private function clearPjsipConfig(&$results)
    {
        try {
            if (!File::exists($this->pjsipPath)) {
                // Nothing to clear
                $results['pjsip_cleared'] = true;
                return;
            }

            // Backup the file
            $backupPath = $this->pjsipPath . '.backup.' . date('YmdHis');
            File::copy($this->pjsipPath, $backupPath);
            Log::info("pjsip.conf backup created: $backupPath");

            // Write clean config
            $cleanConfig = <<<'EOT'
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

EOT;

            File::put($this->pjsipPath, $cleanConfig);
            $results['pjsip_cleared'] = true;
            Log::info('pjsip.conf reset to clean state');

        } catch (\Exception $e) {
            $results['errors'][] = 'pjsip.conf: ' . $e->getMessage();
            Log::error('Failed to reset pjsip.conf: ' . $e->getMessage());
        }
    }

    /**
     * Reset extensions.conf to clean state
     */
    private function clearExtensionsConfig(&$results)
    {
        try {
            if (!File::exists($this->extensionsPath)) {
                // Nothing to clear
                $results['extensions_cleared'] = true;
                return;
            }

            // Backup the file
            $backupPath = $this->extensionsPath . '.backup.' . date('YmdHis');
            File::copy($this->extensionsPath, $backupPath);
            Log::info("extensions.conf backup created: $backupPath");

            // Write clean config
            $cleanConfig = <<<'EOT'
; RayanPBX Dialplan Configuration
; Reset to clean state by RayanPBX Reset Configuration

[general]
static=yes
writeprotect=no

[globals]

[from-internal]
; Add your extension dialplan rules here

EOT;

            File::put($this->extensionsPath, $cleanConfig);
            $results['extensions_cleared'] = true;
            Log::info('extensions.conf reset to clean state');

        } catch (\Exception $e) {
            $results['errors'][] = 'extensions.conf: ' . $e->getMessage();
            Log::error('Failed to reset extensions.conf: ' . $e->getMessage());
        }
    }

    /**
     * Reload Asterisk configuration
     */
    private function reloadAsterisk(&$results)
    {
        try {
            // Check if Asterisk is available
            $output = [];
            $returnCode = 0;
            exec('which asterisk 2>/dev/null', $output, $returnCode);
            
            if ($returnCode !== 0) {
                // Asterisk not installed, skip reload
                $results['asterisk_reloaded'] = true;
                return;
            }

            // Check if Asterisk is running
            $output = [];
            exec('systemctl is-active asterisk 2>/dev/null', $output, $returnCode);
            
            if ($returnCode !== 0) {
                // Asterisk not running, skip reload
                $results['asterisk_reloaded'] = true;
                return;
            }

            // Reload PJSIP module
            exec('asterisk -rx "module reload res_pjsip.so" 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                Log::warning('Failed to reload PJSIP module');
            }

            // Reload dialplan
            exec('asterisk -rx "dialplan reload" 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                Log::warning('Failed to reload dialplan');
            }

            $results['asterisk_reloaded'] = true;
            Log::info('Asterisk configuration reloaded');

        } catch (\Exception $e) {
            $results['errors'][] = 'Asterisk reload: ' . $e->getMessage();
            Log::error('Failed to reload Asterisk: ' . $e->getMessage());
        }
    }
}
