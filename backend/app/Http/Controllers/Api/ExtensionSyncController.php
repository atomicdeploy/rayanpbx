<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Adapters\AsteriskAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ExtensionSyncController extends Controller
{
    private $asterisk;
    private $pjsipConfigPath;
    
    public function __construct(AsteriskAdapter $asterisk)
    {
        $this->asterisk = $asterisk;
        $this->pjsipConfigPath = config('rayanpbx.asterisk.pjsip_config', '/etc/asterisk/pjsip.conf');
    }
    
    /**
     * Get sync status for all extensions
     * Compares database and Asterisk configuration
     */
    public function status()
    {
        // Get all extensions from database
        $dbExtensions = Extension::orderBy('extension_number')->get();
        
        // Parse pjsip.conf to get Asterisk extensions
        $asteriskExtensions = $this->parsePjsipConfig();
        
        // Get live registration status
        $liveStatus = $this->getLiveRegistrationStatus();
        
        // Build sync info
        $syncInfos = [];
        $allExtensions = [];
        
        // Collect all unique extension numbers
        foreach ($dbExtensions as $ext) {
            $allExtensions[$ext->extension_number] = true;
        }
        foreach ($asteriskExtensions as $ext) {
            $allExtensions[$ext['extension_number']] = true;
        }
        
        // Build sync info for each extension
        foreach (array_keys($allExtensions) as $extNum) {
            $dbExt = $dbExtensions->firstWhere('extension_number', $extNum);
            $astExt = collect($asteriskExtensions)->firstWhere('extension_number', $extNum);
            
            $info = [
                'extension_number' => $extNum,
                'db_extension' => $dbExt,
                'asterisk_config' => $astExt,
                'registered' => $liveStatus[$extNum] ?? false,
                'source' => 'both',
                'sync_status' => 'match',
                'differences' => [],
            ];
            
            if ($dbExt && $astExt) {
                $info['source'] = 'both';
                $info['differences'] = $this->findDifferences($dbExt, $astExt);
                $info['sync_status'] = count($info['differences']) > 0 ? 'mismatch' : 'match';
            } elseif ($dbExt) {
                $info['source'] = 'database';
                $info['sync_status'] = 'db_only';
                $info['differences'] = ['Not in Asterisk config'];
            } else {
                $info['source'] = 'asterisk';
                $info['sync_status'] = 'asterisk_only';
                $info['differences'] = ['Not in database'];
            }
            
            $syncInfos[] = $info;
        }
        
        // Build summary
        $summary = [
            'total' => count($syncInfos),
            'matched' => count(array_filter($syncInfos, fn($i) => $i['sync_status'] === 'match')),
            'db_only' => count(array_filter($syncInfos, fn($i) => $i['sync_status'] === 'db_only')),
            'asterisk_only' => count(array_filter($syncInfos, fn($i) => $i['sync_status'] === 'asterisk_only')),
            'mismatched' => count(array_filter($syncInfos, fn($i) => $i['sync_status'] === 'mismatch')),
        ];
        
        return response()->json([
            'summary' => $summary,
            'extensions' => $syncInfos,
        ]);
    }
    
    /**
     * Sync a single extension from database to Asterisk
     */
    public function syncDatabaseToAsterisk(Request $request)
    {
        $validated = $request->validate([
            'extension_number' => 'required|string',
        ]);
        
        $extension = Extension::where('extension_number', $validated['extension_number'])->first();
        
        if (!$extension) {
            return response()->json([
                'error' => 'Extension not found in database',
            ], 404);
        }
        
        try {
            // Generate and write PJSIP config
            $config = $this->asterisk->generatePjsipEndpoint($extension);
            $success = $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
            
            if (!$success) {
                return response()->json([
                    'error' => 'Failed to write PJSIP configuration',
                ], 500);
            }
            
            // Reload Asterisk
            $this->asterisk->reload();
            
            return response()->json([
                'message' => "Extension {$extension->extension_number} synced to Asterisk",
                'success' => true,
            ]);
        } catch (\Exception $e) {
            Log::error("Sync DB to Asterisk failed: " . $e->getMessage());
            return response()->json([
                'error' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Sync a single extension from Asterisk to database
     */
    public function syncAsteriskToDatabase(Request $request)
    {
        $validated = $request->validate([
            'extension_number' => 'required|string',
        ]);
        
        $asteriskExtensions = $this->parsePjsipConfig();
        $astExt = collect($asteriskExtensions)->firstWhere('extension_number', $validated['extension_number']);
        
        if (!$astExt) {
            return response()->json([
                'error' => 'Extension not found in Asterisk config',
            ], 404);
        }
        
        try {
            // Check if extension exists in database
            $extension = Extension::where('extension_number', $validated['extension_number'])->first();
            
            if ($extension) {
                // Update existing extension
                $extension->update([
                    'context' => $astExt['context'] ?? 'from-internal',
                    'transport' => $astExt['transport'] ?? 'transport-udp',
                    'max_contacts' => $astExt['max_contacts'] ?? 1,
                    'qualify_frequency' => $astExt['qualify_frequency'] ?? 60,
                    'direct_media' => $astExt['direct_media'] ?? 'no',
                    'codecs' => $astExt['codecs'] ?? ['ulaw', 'alaw', 'g722'],
                ]);
            } else {
                // Create new extension
                $extension = Extension::create([
                    'extension_number' => $validated['extension_number'],
                    'name' => "Extension {$validated['extension_number']}",
                    'secret' => $astExt['secret'] ?? '',
                    'context' => $astExt['context'] ?? 'from-internal',
                    'transport' => $astExt['transport'] ?? 'transport-udp',
                    'max_contacts' => $astExt['max_contacts'] ?? 1,
                    'qualify_frequency' => $astExt['qualify_frequency'] ?? 60,
                    'direct_media' => $astExt['direct_media'] ?? 'no',
                    'codecs' => $astExt['codecs'] ?? ['ulaw', 'alaw', 'g722'],
                    'enabled' => true,
                ]);
            }
            
            return response()->json([
                'message' => "Extension {$validated['extension_number']} synced to database",
                'success' => true,
                'extension' => $extension,
            ]);
        } catch (\Exception $e) {
            Log::error("Sync Asterisk to DB failed: " . $e->getMessage());
            return response()->json([
                'error' => 'Sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Sync all extensions from database to Asterisk
     */
    public function syncAllDatabaseToAsterisk()
    {
        $extensions = Extension::where('enabled', true)->get();
        $synced = 0;
        $errors = [];
        
        foreach ($extensions as $extension) {
            try {
                $config = $this->asterisk->generatePjsipEndpoint($extension);
                $success = $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");
                
                if ($success) {
                    $synced++;
                } else {
                    $errors[] = "Failed to write config for {$extension->extension_number}";
                }
            } catch (\Exception $e) {
                $errors[] = "{$extension->extension_number}: " . $e->getMessage();
            }
        }
        
        // Reload Asterisk
        $this->asterisk->reload();
        
        return response()->json([
            'message' => "Synced $synced extensions to Asterisk",
            'synced' => $synced,
            'errors' => $errors,
            'success' => count($errors) === 0,
        ]);
    }
    
    /**
     * Sync all extensions from Asterisk to database
     */
    public function syncAllAsteriskToDatabase()
    {
        $asteriskExtensions = $this->parsePjsipConfig();
        $synced = 0;
        $errors = [];
        
        foreach ($asteriskExtensions as $astExt) {
            try {
                $extension = Extension::where('extension_number', $astExt['extension_number'])->first();
                
                $data = [
                    'extension_number' => $astExt['extension_number'],
                    'name' => $extension?->name ?? "Extension {$astExt['extension_number']}",
                    'context' => $astExt['context'] ?? 'from-internal',
                    'transport' => $astExt['transport'] ?? 'transport-udp',
                    'max_contacts' => $astExt['max_contacts'] ?? 1,
                    'qualify_frequency' => $astExt['qualify_frequency'] ?? 60,
                    'direct_media' => $astExt['direct_media'] ?? 'no',
                    'codecs' => $astExt['codecs'] ?? ['ulaw', 'alaw', 'g722'],
                    'enabled' => true,
                ];
                
                if (!empty($astExt['secret'])) {
                    $data['secret'] = $astExt['secret'];
                }
                
                Extension::updateOrCreate(
                    ['extension_number' => $astExt['extension_number']],
                    $data
                );
                
                $synced++;
            } catch (\Exception $e) {
                $errors[] = "{$astExt['extension_number']}: " . $e->getMessage();
            }
        }
        
        return response()->json([
            'message' => "Synced $synced extensions to database",
            'synced' => $synced,
            'errors' => $errors,
            'success' => count($errors) === 0,
        ]);
    }
    
    /**
     * Parse pjsip.conf and extract extensions
     */
    private function parsePjsipConfig(): array
    {
        if (!file_exists($this->pjsipConfigPath)) {
            return [];
        }
        
        $content = file_get_contents($this->pjsipConfigPath);
        if (!$content) {
            return [];
        }
        
        $extensions = [];
        $lines = explode("\n", $content);
        $currentSection = null;
        $currentType = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, ';')) {
                continue;
            }
            
            // Check for section header [name]
            if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
                $currentSection = substr($line, 1, -1);
                $currentType = null;
                continue;
            }
            
            // Skip non-extension sections
            if (!$currentSection || $currentSection === 'global' || str_starts_with($currentSection, 'transport-')) {
                continue;
            }
            
            // Parse key=value
            if (!str_contains($line, '=')) {
                continue;
            }
            
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Check the type of this section
            if ($key === 'type') {
                $currentType = $value;
                continue;
            }
            
            // Skip identify sections (used for trunks)
            if ($currentType === 'identify') {
                continue;
            }
            
            // Only process numeric extensions
            if (!preg_match('/^\d+$/', $currentSection)) {
                continue;
            }
            
            // Get or create extension entry
            if (!isset($extensions[$currentSection])) {
                $extensions[$currentSection] = [
                    'extension_number' => $currentSection,
                    'max_contacts' => 1,
                    'qualify_frequency' => 60,
                    'direct_media' => 'no',
                    'codecs' => [],
                ];
            }
            
            // Parse properties based on type
            switch ($currentType) {
                case 'endpoint':
                    match ($key) {
                        'context' => $extensions[$currentSection]['context'] = $value,
                        'transport' => $extensions[$currentSection]['transport'] = $value,
                        'allow' => $extensions[$currentSection]['codecs'][] = $value,
                        'callerid' => $extensions[$currentSection]['caller_id'] = $value,
                        'direct_media' => $extensions[$currentSection]['direct_media'] = $value,
                        default => null,
                    };
                    break;
                case 'auth':
                    if ($key === 'password') {
                        $extensions[$currentSection]['secret'] = $value;
                    }
                    break;
                case 'aor':
                    match ($key) {
                        'max_contacts' => $extensions[$currentSection]['max_contacts'] = (int) $value,
                        'qualify_frequency' => $extensions[$currentSection]['qualify_frequency'] = (int) $value,
                        default => null,
                    };
                    break;
            }
        }
        
        return array_values($extensions);
    }
    
    /**
     * Get live registration status from Asterisk
     */
    private function getLiveRegistrationStatus(): array
    {
        $endpoints = $this->asterisk->getAllPjsipEndpoints();
        $status = [];
        
        foreach ($endpoints as $endpoint) {
            $name = $endpoint['name'] ?? '';
            if (preg_match('/^\d+$/', $name)) {
                $status[$name] = ($endpoint['state'] ?? '') !== 'Unavailable';
            }
        }
        
        return $status;
    }
    
    /**
     * Find differences between database and Asterisk extension
     */
    private function findDifferences($dbExt, $astExt): array
    {
        $diffs = [];
        
        // Compare context
        $dbContext = $dbExt->context ?? 'from-internal';
        $astContext = $astExt['context'] ?? 'from-internal';
        if ($dbContext !== $astContext) {
            $diffs[] = "Context: DB={$dbContext}, Asterisk={$astContext}";
        }
        
        // Compare transport
        $dbTransport = $dbExt->transport ?? 'transport-udp';
        $astTransport = $astExt['transport'] ?? 'transport-udp';
        if ($dbTransport !== $astTransport) {
            $diffs[] = "Transport: DB={$dbTransport}, Asterisk={$astTransport}";
        }
        
        // Compare max_contacts
        $dbMaxContacts = $dbExt->max_contacts ?? 1;
        $astMaxContacts = $astExt['max_contacts'] ?? 1;
        if ($dbMaxContacts !== $astMaxContacts) {
            $diffs[] = "Max Contacts: DB={$dbMaxContacts}, Asterisk={$astMaxContacts}";
        }
        
        // Compare direct_media
        $dbDirectMedia = $dbExt->direct_media ?? 'no';
        $astDirectMedia = $astExt['direct_media'] ?? 'no';
        if ($dbDirectMedia !== $astDirectMedia) {
            $diffs[] = "Direct Media: DB={$dbDirectMedia}, Asterisk={$astDirectMedia}";
        }
        
        return $diffs;
    }
}
