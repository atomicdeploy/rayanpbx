<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Adapters\AsteriskAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PjsipConfigController extends Controller
{
    private $asterisk;
    private $pjsipConfigPath;
    
    public function __construct(AsteriskAdapter $asterisk)
    {
        $this->asterisk = $asterisk;
        $this->pjsipConfigPath = config('rayanpbx.asterisk.pjsip_config', '/etc/asterisk/pjsip.conf');
    }
    
    /**
     * Get current PJSIP global configuration
     */
    public function getGlobal()
    {
        try {
            $config = @file_get_contents($this->pjsipConfigPath);
            
            if (!$config) {
                return response()->json([
                    'error' => 'Unable to read PJSIP configuration',
                ], 500);
            }
            
            // Parse global settings
            $settings = $this->parseGlobalSettings($config);
            
            return response()->json([
                'settings' => $settings,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Update external media address
     */
    public function updateExternalMedia(Request $request)
    {
        $validated = $request->validate([
            'external_media_address' => 'nullable|string|max:255',
            'external_signaling_address' => 'nullable|string|max:255',
            'local_net' => 'nullable|string|max:255',
        ]);
        
        try {
            $config = @file_get_contents($this->pjsipConfigPath);
            
            if (!$config) {
                return response()->json([
                    'error' => 'Unable to read PJSIP configuration',
                ], 500);
            }
            
            // Update or add global settings
            $config = $this->updateGlobalSettings($config, $validated);
            
            // Write back to file
            if (file_put_contents($this->pjsipConfigPath, $config) === false) {
                return response()->json([
                    'error' => 'Unable to write PJSIP configuration',
                ], 500);
            }
            
            // Reload PJSIP
            $reloadResult = $this->asterisk->reloadCLI();
            
            // Clear cache
            Cache::forget('pjsip_global_settings');
            
            return response()->json([
                'message' => 'External media settings updated successfully',
                'reload_result' => $reloadResult,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Update transport configuration
     */
    public function updateTransport(Request $request)
    {
        $validated = $request->validate([
            'protocol' => 'required|in:udp,tcp,tls',
            'bind' => 'required|string|max:255',
        ]);
        
        try {
            $config = @file_get_contents($this->pjsipConfigPath);
            
            if (!$config) {
                return response()->json([
                    'error' => 'Unable to read PJSIP configuration',
                ], 500);
            }
            
            // Update transport settings
            $config = $this->updateTransportSettings($config, $validated);
            
            // Write back to file
            if (file_put_contents($this->pjsipConfigPath, $config) === false) {
                return response()->json([
                    'error' => 'Unable to write PJSIP configuration',
                ], 500);
            }
            
            // Reload PJSIP
            $reloadResult = $this->asterisk->reloadCLI();
            
            return response()->json([
                'message' => 'Transport settings updated successfully',
                'reload_result' => $reloadResult,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Parse global settings from config
     */
    private function parseGlobalSettings($config)
    {
        $settings = [
            'external_media_address' => null,
            'external_signaling_address' => null,
            'local_net' => null,
        ];
        
        $lines = explode("\n", $config);
        $inGlobal = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if ($line === '[global]') {
                $inGlobal = true;
                continue;
            }
            
            if ($inGlobal) {
                if (empty($line) || $line[0] === '[') {
                    $inGlobal = false;
                    continue;
                }
                
                if (preg_match('/^external_media_address\s*=\s*(.+)$/', $line, $matches)) {
                    $settings['external_media_address'] = trim($matches[1]);
                }
                
                if (preg_match('/^external_signaling_address\s*=\s*(.+)$/', $line, $matches)) {
                    $settings['external_signaling_address'] = trim($matches[1]);
                }
                
                if (preg_match('/^local_net\s*=\s*(.+)$/', $line, $matches)) {
                    $settings['local_net'] = trim($matches[1]);
                }
            }
        }
        
        return $settings;
    }
    
    /**
     * Update global settings in config
     */
    private function updateGlobalSettings($config, $settings)
    {
        $lines = explode("\n", $config);
        $result = [];
        $inGlobal = false;
        $globalProcessed = false;
        $hasGlobal = false;
        
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            
            if ($trimmedLine === '[global]') {
                $inGlobal = true;
                $hasGlobal = true;
                $result[] = $line;
                continue;
            }
            
            if ($inGlobal) {
                if (empty($trimmedLine) || $trimmedLine[0] === '[') {
                    // End of global section, add our settings if not already there
                    if (!$globalProcessed) {
                        if (isset($settings['external_media_address']) && !empty($settings['external_media_address'])) {
                            $result[] = "external_media_address={$settings['external_media_address']}";
                        }
                        if (isset($settings['external_signaling_address']) && !empty($settings['external_signaling_address'])) {
                            $result[] = "external_signaling_address={$settings['external_signaling_address']}";
                        }
                        if (isset($settings['local_net']) && !empty($settings['local_net'])) {
                            $result[] = "local_net={$settings['local_net']}";
                        }
                        $globalProcessed = true;
                    }
                    $inGlobal = false;
                    $result[] = $line;
                    continue;
                }
                
                // Skip existing external_media_address, external_signaling_address, and local_net lines
                if (preg_match('/^external_(media|signaling)_address\s*=/', $trimmedLine) ||
                    preg_match('/^local_net\s*=/', $trimmedLine)) {
                    continue;
                }
            }
            
            $result[] = $line;
        }
        
        // If global section doesn't exist, add it
        if (!$hasGlobal) {
            $globalSection = ["\n[global]"];
            if (isset($settings['external_media_address']) && !empty($settings['external_media_address'])) {
                $globalSection[] = "external_media_address={$settings['external_media_address']}";
            }
            if (isset($settings['external_signaling_address']) && !empty($settings['external_signaling_address'])) {
                $globalSection[] = "external_signaling_address={$settings['external_signaling_address']}";
            }
            if (isset($settings['local_net']) && !empty($settings['local_net'])) {
                $globalSection[] = "local_net={$settings['local_net']}";
            }
            $globalSection[] = "";
            
            // Insert after any comments at the beginning
            $insertPos = 0;
            foreach ($result as $i => $line) {
                if (trim($line) === '' || trim($line)[0] === ';') {
                    $insertPos = $i + 1;
                } else {
                    break;
                }
            }
            
            array_splice($result, $insertPos, 0, $globalSection);
        }
        
        return implode("\n", $result);
    }
    
    /**
     * Update transport settings in config
     */
    private function updateTransportSettings($config, $settings)
    {
        // For simplicity, we'll update the managed transport section
        $pattern = "/; BEGIN MANAGED - RayanPBX Transport.*?; END MANAGED - RayanPBX Transport\n/s";
        
        $transportConfig = "; BEGIN MANAGED - RayanPBX Transport\n";
        $transportConfig .= "[transport-{$settings['protocol']}]\n";
        $transportConfig .= "type=transport\n";
        $transportConfig .= "protocol={$settings['protocol']}\n";
        $transportConfig .= "bind={$settings['bind']}\n";
        $transportConfig .= "; END MANAGED - RayanPBX Transport\n";
        
        if (preg_match($pattern, $config)) {
            $config = preg_replace($pattern, $transportConfig, $config);
        } else {
            // Add at the beginning
            $config = $transportConfig . "\n" . $config;
        }
        
        return $config;
    }
}
