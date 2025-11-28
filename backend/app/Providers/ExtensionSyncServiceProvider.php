<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Adapters\AsteriskAdapter;
use App\Models\Extension;
use Exception;

class ExtensionSyncServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     * 
     * Performs automatic bidirectional extension sync on application startup:
     * - Extensions only in DB are synced to Asterisk
     * - Extensions only in Asterisk are synced to DB
     * - Conflicts are logged for admin attention
     * 
     * Throttled to run at most once per minute to avoid performance impact.
     */
    public function boot(): void
    {
        // Only run sync on console/web requests, not during migrations or tests
        if ($this->app->runningInConsole() && !$this->shouldRunInConsole()) {
            return;
        }

        // Defer sync to after request handling to avoid slowing down boot
        $this->app->booted(function () {
            $this->performAutoSync();
        });
    }

    /**
     * Check if we should run sync in console mode
     */
    protected function shouldRunInConsole(): bool
    {
        // Only run on specific artisan commands
        if (!isset($_SERVER['argv'])) {
            return false;
        }
        
        $command = $_SERVER['argv'][1] ?? '';
        
        // Commands that should trigger sync
        $syncCommands = [
            'serve',
            'rayanpbx:sync',
            'queue:work',
            'schedule:run',
        ];
        
        return in_array($command, $syncCommands);
    }

    /**
     * Perform automatic bidirectional sync
     * Throttled to run at most once per minute to avoid performance impact.
     */
    protected function performAutoSync(): void
    {
        try {
            // Throttle sync to run at most once per minute
            $cacheKey = 'extension_sync_last_run';
            if (Cache::has($cacheKey)) {
                Log::debug('ExtensionSync: Skipping - already ran within the last minute');
                return;
            }
            
            // Check if database is available
            if (!$this->isDatabaseAvailable()) {
                return;
            }

            $asterisk = app(AsteriskAdapter::class);
            $pjsipConfPath = config('asterisk.pjsip_conf', '/etc/asterisk/pjsip.conf');
            
            if (!file_exists($pjsipConfPath)) {
                Log::debug('ExtensionSync: pjsip.conf not found, skipping auto-sync');
                return;
            }
            
            // Set throttle cache for 60 seconds
            Cache::put($cacheKey, true, 60);

            // Parse Asterisk config
            $asteriskExtensions = $this->parseAsteriskConfig($pjsipConfPath);
            
            // Get database extensions
            $dbExtensions = Extension::all()->keyBy('extension_number');
            
            $synced = 0;
            $conflicts = [];
            
            // Sync Asterisk-only extensions to DB
            foreach ($asteriskExtensions as $extNumber => $astExt) {
                if (!$dbExtensions->has($extNumber)) {
                    try {
                        Extension::create([
                            'extension_number' => $extNumber,
                            'name' => $astExt['caller_id'] ?? "Extension {$extNumber}",
                            'secret' => $astExt['secret'] ?? '',
                            'context' => $astExt['context'] ?? 'from-internal',
                            'transport' => $astExt['transport'] ?? 'transport-udp',
                            'max_contacts' => $astExt['max_contacts'] ?? 1,
                            'qualify_frequency' => $astExt['qualify_frequency'] ?? 60,
                            'direct_media' => ($astExt['direct_media'] ?? 'no') === 'yes',
                            'codecs' => json_encode($astExt['codecs'] ?? ['ulaw', 'alaw', 'g722']),
                            'enabled' => true,
                        ]);
                        $synced++;
                        Log::info("ExtensionSync: Imported extension {$extNumber} from Asterisk to DB");
                    } catch (Exception $e) {
                        Log::warning("ExtensionSync: Failed to import extension {$extNumber}: " . $e->getMessage());
                    }
                }
            }
            
            // Sync DB-only extensions to Asterisk
            foreach ($dbExtensions as $extNumber => $dbExt) {
                if (!isset($asteriskExtensions[$extNumber])) {
                    try {
                        $asterisk->createPjsipEndpoint($dbExt);
                        $synced++;
                        Log::info("ExtensionSync: Exported extension {$extNumber} from DB to Asterisk");
                    } catch (Exception $e) {
                        Log::warning("ExtensionSync: Failed to export extension {$extNumber}: " . $e->getMessage());
                    }
                }
            }
            
            // Check for mismatches (both exist but differ)
            foreach ($dbExtensions as $extNumber => $dbExt) {
                if (isset($asteriskExtensions[$extNumber])) {
                    $astExt = $asteriskExtensions[$extNumber];
                    $differences = $this->compareExtensions($dbExt, $astExt);
                    
                    if (!empty($differences)) {
                        $conflicts[] = [
                            'extension' => $extNumber,
                            'differences' => $differences,
                        ];
                    }
                }
            }
            
            // Log summary
            if ($synced > 0) {
                Log::info("ExtensionSync: Auto-synced {$synced} extension(s)");
            }
            
            if (!empty($conflicts)) {
                Log::warning("ExtensionSync: Found " . count($conflicts) . " conflict(s) requiring attention:", $conflicts);
            }
            
            // Reload Asterisk if we made changes
            if ($synced > 0) {
                try {
                    $asterisk->reloadPjsip();
                } catch (Exception $e) {
                    Log::warning("ExtensionSync: Failed to reload Asterisk: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            Log::warning('ExtensionSync: Auto-sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if database is available
     */
    protected function isDatabaseAvailable(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Parse Asterisk pjsip.conf for extensions
     */
    protected function parseAsteriskConfig(string $path): array
    {
        $content = file_get_contents($path);
        $lines = explode("\n", $content);
        
        $extensions = [];
        $currentSection = null;
        $currentType = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines (compatible with PHP 7.x)
            if (empty($line) || (strlen($line) > 0 && $line[0] === ';')) {
                continue;
            }
            
            // Check for section header
            if (preg_match('/^\[(\d+)\]$/', $line, $matches)) {
                $currentSection = $matches[1];
                $currentType = 'endpoint';
                
                if (!isset($extensions[$currentSection])) {
                    $extensions[$currentSection] = [
                        'extension_number' => $currentSection,
                        'context' => 'from-internal',
                        'transport' => 'transport-udp',
                        'max_contacts' => 1,
                        'qualify_frequency' => 60,
                        'direct_media' => 'no',
                        'codecs' => [],
                    ];
                }
                continue;
            }
            
            // Check for auth section
            if (preg_match('/^\[(\d+)-auth\]$/', $line, $matches)) {
                $currentSection = $matches[1];
                $currentType = 'auth';
                continue;
            }
            
            // Check for aor section
            if (preg_match('/^\[(\d+)-aor\]$/', $line, $matches)) {
                $currentSection = $matches[1];
                $currentType = 'aor';
                continue;
            }
            
            // Skip non-extension sections
            if (preg_match('/^\[/', $line)) {
                $currentSection = null;
                $currentType = null;
                continue;
            }
            
            // Skip if not in an extension section
            if ($currentSection === null || !isset($extensions[$currentSection])) {
                continue;
            }
            
            // Parse key=value
            if (preg_match('/^([^=]+)=(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                
                switch ($currentType) {
                    case 'endpoint':
                        switch ($key) {
                            case 'context':
                                $extensions[$currentSection]['context'] = $value;
                                break;
                            case 'transport':
                                $extensions[$currentSection]['transport'] = $value;
                                break;
                            case 'allow':
                                $extensions[$currentSection]['codecs'][] = $value;
                                break;
                            case 'callerid':
                                $extensions[$currentSection]['caller_id'] = $value;
                                break;
                            case 'direct_media':
                                $extensions[$currentSection]['direct_media'] = $value;
                                break;
                        }
                        break;
                    case 'auth':
                        if ($key === 'password') {
                            $extensions[$currentSection]['secret'] = $value;
                        }
                        break;
                    case 'aor':
                        switch ($key) {
                            case 'max_contacts':
                                $extensions[$currentSection]['max_contacts'] = (int) $value;
                                break;
                            case 'qualify_frequency':
                                $extensions[$currentSection]['qualify_frequency'] = (int) $value;
                                break;
                        }
                        break;
                }
            }
        }
        
        return $extensions;
    }

    /**
     * Compare a database extension with Asterisk config
     */
    protected function compareExtensions(Extension $dbExt, array $astExt): array
    {
        $differences = [];
        
        // Compare context
        if ($dbExt->context !== ($astExt['context'] ?? 'from-internal')) {
            $differences[] = "context: DB={$dbExt->context}, Asterisk=" . ($astExt['context'] ?? 'from-internal');
        }
        
        // Compare transport
        if ($dbExt->transport !== ($astExt['transport'] ?? 'transport-udp')) {
            $differences[] = "transport: DB={$dbExt->transport}, Asterisk=" . ($astExt['transport'] ?? 'transport-udp');
        }
        
        // Compare max_contacts
        if ((int)$dbExt->max_contacts !== (int)($astExt['max_contacts'] ?? 1)) {
            $differences[] = "max_contacts: DB={$dbExt->max_contacts}, Asterisk=" . ($astExt['max_contacts'] ?? 1);
        }
        
        // Compare secret (if both have one)
        if (!empty($dbExt->secret) && !empty($astExt['secret']) && $dbExt->secret !== $astExt['secret']) {
            $differences[] = "secret: values differ";
        }
        
        return $differences;
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }
}
