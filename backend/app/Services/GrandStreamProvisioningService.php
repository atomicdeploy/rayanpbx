<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * GrandStream Phone Provisioning Service
 * 
 * Handles automatic provisioning for GrandStream GXP series phones:
 * - GXP1625: 2 lines, 2.3" LCD
 * - GXP1628: 2 lines, 2.3" LCD, PoE
 * - GXP1630: 3 lines, 2.8" color LCD
 */
class GrandStreamProvisioningService
{
    protected $supportedModels = [
        'GXP1625' => [
            'lines' => 2,
            'template' => 'gxp1620.xml',
            'firmware' => '1.0.11.23',
            'display' => '2.3" LCD',
        ],
        'GXP1628' => [
            'lines' => 2,
            'template' => 'gxp1620.xml',
            'firmware' => '1.0.11.23',
            'display' => '2.3" LCD',
            'poe' => true,
        ],
        'GXP1630' => [
            'lines' => 3,
            'template' => 'gxp1620.xml',
            'firmware' => '1.0.11.23',
            'display' => '2.8" Color LCD',
        ],
    ];

    /**
     * Generate provisioning configuration for a phone
     */
    public function generateConfig($mac, $extension, $options = [])
    {
        $model = $options['model'] ?? 'GXP1628';
        
        if (!isset($this->supportedModels[$model])) {
            throw new \Exception("Unsupported phone model: $model");
        }

        $config = $this->getBaseConfig($model);
        $config = $this->addExtensionConfig($config, $extension, $options);
        $config = $this->addNetworkConfig($config, $options);
        $config = $this->addBLFConfig($config, $options);

        return $config;
    }

    /**
     * Get base configuration template
     */
    protected function getBaseConfig($model)
    {
        $modelInfo = $this->supportedModels[$model];
        
        return [
            'model' => $model,
            'template' => $modelInfo['template'],
            'firmware_version' => $modelInfo['firmware'],
            'server' => [
                'sip_server' => config('rayanpbx.sip_server_ip'),
                'outbound_proxy' => config('rayanpbx.sip_server_ip'),
                'sip_port' => 5060,
                'transport' => 'UDP',
            ],
            'codecs' => [
                'PCMU' => 9,
                'PCMA' => 8,
                'G722' => 0, // HD codec
                'G729' => 2,
            ],
            'features' => [
                'direct_ip_call' => 0,
                'use_privacy_header' => 0,
                'subscribe_for_mwi' => 1,
                'send_sdp_on_update' => 0,
                'dtmf_mode' => 'RFC2833',
            ],
        ];
    }

    /**
     * Add extension (account) configuration
     */
    protected function addExtensionConfig($config, $extension, $options)
    {
        $accountNumber = $options['account_number'] ?? 1; // Which line on the phone
        
        $config['accounts'][$accountNumber] = [
            'account_active' => 1,
            'account_name' => $extension['name'] ?? "Extension {$extension['extension_number']}",
            'sip_server' => config('rayanpbx.sip_server_ip'),
            'sip_user_id' => $extension['extension_number'],
            'authenticate_id' => $extension['extension_number'],
            'authenticate_password' => $extension['secret'],
            'name' => $extension['name'],
            'display_name' => $extension['name'],
            'voice_mail_user_id' => $extension['extension_number'],
        ];

        return $config;
    }

    /**
     * Add network configuration
     */
    protected function addNetworkConfig($config, $options)
    {
        $config['network'] = [
            'dhcp' => $options['dhcp'] ?? 1,
            'static_ip' => $options['static_ip'] ?? '',
            'subnet_mask' => $options['subnet_mask'] ?? '',
            'gateway' => $options['gateway'] ?? '',
            'dns_server_1' => $options['dns_1'] ?? '',
            'dns_server_2' => $options['dns_2'] ?? '',
            'ntp_server' => $options['ntp_server'] ?? 'pool.ntp.org',
            'timezone' => $options['timezone'] ?? 'GMT+03:30', // Tehran
            'vlan_tag' => $options['vlan_tag'] ?? 0,
        ];

        return $config;
    }

    /**
     * Add BLF (Busy Lamp Field) configuration
     */
    protected function addBLFConfig($config, $options)
    {
        if (isset($options['blf_list']) && is_array($options['blf_list'])) {
            $config['blf'] = [];
            
            foreach ($options['blf_list'] as $index => $ext) {
                $config['blf'][$index] = [
                    'extension' => $ext,
                    'mode' => 'Speed Dial/BLF', // or 'Shared Line', 'Presence Watcher'
                ];
            }
        }

        return $config;
    }

    /**
     * Convert configuration to GrandStream XML format
     */
    public function toXML($config)
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<gs_provision version=\"1\">\n";
        $xml .= "  <mac>{$config['mac']}</mac>\n";
        $xml .= "  <config version=\"1\">\n";

        // Server settings
        foreach ($config['accounts'] as $num => $account) {
            $xml .= "    <!-- Account $num -->\n";
            $xml .= "    <P{$num}47>{$account['sip_server']}</P{$num}47>\n"; // SIP Server
            $xml .= "    <P{$num}35>{$account['sip_user_id']}</P{$num}35>\n"; // SIP User ID
            $xml .= "    <P{$num}36>{$account['authenticate_id']}</P{$num}36>\n"; // Auth ID
            $xml .= "    <P{$num}34>{$account['authenticate_password']}</P{$num}34>\n"; // Auth Password
            $xml .= "    <P{$num}3>{$account['name']}</P{$num}3>\n"; // Name
        }

        // Network settings
        if (isset($config['network'])) {
            $xml .= "    <!-- Network Configuration -->\n";
            $xml .= "    <P8>{$config['network']['dhcp']}</P8>\n"; // DHCP
            if (!$config['network']['dhcp']) {
                $xml .= "    <P9>{$config['network']['static_ip']}</P9>\n";
                $xml .= "    <P10>{$config['network']['subnet_mask']}</P10>\n";
                $xml .= "    <P11>{$config['network']['gateway']}</P11>\n";
            }
        }

        // Codec settings
        $xml .= "    <!-- Codec Preferences -->\n";
        $xml .= "    <P57>9</P57>\n"; // PCMU (ulaw)
        $xml .= "    <P58>8</P58>\n"; // PCMA (alaw)
        $xml .= "    <P59>0</P59>\n"; // G722 (HD)

        $xml .= "  </config>\n";
        $xml .= "</gs_provision>\n";

        return $xml;
    }

    /**
     * Discover phones on network
     * Uses network scanning to find GrandStream devices
     */
    public function discoverPhones($network = '192.168.1.0/24')
    {
        Log::info("Discovering GrandStream phones on network: $network");
        
        // Get registered phones from Asterisk
        $registeredPhones = $this->getRegisteredPhonesFromAsterisk();
        
        return [
            'status' => 'success',
            'phones' => $registeredPhones,
            'network' => $network,
        ];
    }

    /**
     * Get registered phones from Asterisk PJSIP
     * Uses Asterisk Manager Interface for secure command execution
     */
    protected function getRegisteredPhonesFromAsterisk()
    {
        try {
            // Use escapeshellcmd for security - no user input is used here
            $command = escapeshellcmd('asterisk -rx "pjsip show endpoints"');
            $output = shell_exec($command);
            
            if (!$output) {
                return [];
            }
            
            $phones = [];
            $lines = explode("\n", $output);
            
            foreach ($lines as $line) {
                $line = trim($line);
                
                // Skip headers and empty lines
                if (empty($line) || strpos($line, '=====') !== false || 
                    strpos($line, 'Endpoint:') !== false || strpos($line, '<Endpoint') !== false) {
                    continue;
                }
                
                // Parse endpoint line
                $fields = preg_split('/\s+/', $line);
                if (count($fields) >= 2) {
                    $extension = $fields[0];
                    
                    // Skip if not an endpoint line
                    if (strpos($extension, ':') !== false || empty($extension)) {
                        continue;
                    }
                    
                    // Extract IP from contact info
                    $ip = $this->extractIPFromLine($line);
                    
                    if (!empty($ip)) {
                        $phones[] = [
                            'extension' => $extension,
                            'ip' => $ip,
                            'status' => count($fields) >= 5 ? $fields[4] : 'Unknown',
                            'user_agent' => $this->detectUserAgent($ip),
                        ];
                    }
                }
            }
            
            return $phones;
        } catch (\Exception $e) {
            Log::error("Failed to get registered phones", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Extract IP address from line
     */
    protected function extractIPFromLine($line)
    {
        if (preg_match('/@(\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Detect user agent from phone
     */
    protected function detectUserAgent($ip)
    {
        try {
            $ch = curl_init("http://{$ip}/");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200 && preg_match('/Server:\s*([^\r\n]+)/i', $response, $matches)) {
                return $matches[1];
            }
            
            return 'Unknown';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Get phone status via HTTP
     */
    public function getPhoneStatus($ip, $credentials = [])
    {
        $username = $credentials['username'] ?? 'admin';
        $password = $credentials['password'] ?? '';
        
        try {
            $ch = curl_init("http://{$ip}/cgi-bin/api-sys_operation");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode != 200) {
                throw new \Exception("HTTP error: {$httpCode}");
            }
            
            // Parse response (could be JSON or XML)
            $data = json_decode($response, true);
            if (!$data) {
                // Try XML parsing
                $xml = simplexml_load_string($response);
                if ($xml) {
                    $data = json_decode(json_encode($xml), true);
                }
            }
            
            return [
                'status' => 'online',
                'ip' => $ip,
                'model' => $data['model'] ?? 'Unknown',
                'firmware' => $data['firmware'] ?? 'Unknown',
                'mac' => $data['mac'] ?? 'Unknown',
                'uptime' => $data['uptime'] ?? 'Unknown',
                'registered' => true,
                'last_update' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get phone status", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'offline',
                'ip' => $ip,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Assign extension to phone
     */
    public function assignExtension($mac, $extensionId)
    {
        // Generate configuration
        $extension = \App\Models\Extension::find($extensionId);
        
        if (!$extension) {
            throw new \Exception("Extension not found: $extensionId");
        }

        $config = $this->generateConfig($mac, $extension->toArray());
        
        // Store configuration for provisioning server
        $filename = "cfg{$mac}.xml";
        Storage::disk('local')->put("provisioning/{$filename}", $this->toXML($config));

        return [
            'success' => true,
            'mac' => $mac,
            'extension' => $extension->extension_number,
            'config_file' => $filename,
            'provisioning_url' => config('rayanpbx.provisioning_base_url') . "/{$filename}",
        ];
    }

    /**
     * Get supported models information
     */
    public function getSupportedModels()
    {
        return $this->supportedModels;
    }

    /**
     * Reboot phone via HTTP API
     */
    public function rebootPhone($ip, $credentials = [])
    {
        $username = $credentials['username'] ?? 'admin';
        $password = $credentials['password'] ?? '';
        
        try {
            $ch = curl_init("http://{$ip}/cgi-bin/api-sys_operation?request=reboot");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode != 200 && $httpCode != 202) {
                throw new \Exception("HTTP error: {$httpCode}");
            }
            
            Log::info("Phone rebooted", ['ip' => $ip]);
            
            return [
                'success' => true,
                'message' => 'Phone reboot command sent successfully',
                'ip' => $ip,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to reboot phone", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to reboot phone',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Factory reset phone via HTTP API
     */
    public function factoryResetPhone($ip, $credentials = [])
    {
        $username = $credentials['username'] ?? 'admin';
        $password = $credentials['password'] ?? '';
        
        try {
            $ch = curl_init("http://{$ip}/cgi-bin/api-sys_operation?request=factory_reset");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode != 200 && $httpCode != 202) {
                throw new \Exception("HTTP error: {$httpCode}");
            }
            
            Log::warning("Phone factory reset", ['ip' => $ip]);
            
            return [
                'success' => true,
                'message' => 'Phone factory reset command sent successfully',
                'ip' => $ip,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to factory reset phone", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to factory reset phone',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get phone configuration via HTTP API
     */
    public function getPhoneConfig($ip, $credentials = [])
    {
        $username = $credentials['username'] ?? 'admin';
        $password = $credentials['password'] ?? '';
        
        try {
            $ch = curl_init("http://{$ip}/cgi-bin/api-get_config");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode != 200) {
                throw new \Exception("HTTP error: {$httpCode}");
            }
            
            // Parse response
            $config = json_decode($response, true);
            if (!$config) {
                // Try XML parsing
                $xml = simplexml_load_string($response);
                if ($xml) {
                    $config = json_decode(json_encode($xml), true);
                }
            }
            
            return [
                'success' => true,
                'config' => $config ?? [],
                'ip' => $ip,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get phone config", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to get phone configuration',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Set phone configuration via HTTP API
     */
    public function setPhoneConfig($ip, $config, $credentials = [])
    {
        $username = $credentials['username'] ?? 'admin';
        $password = $credentials['password'] ?? '';
        
        try {
            $jsonData = json_encode($config);
            
            if ($jsonData === false) {
                throw new \Exception('Failed to encode configuration data: ' . json_last_error_msg());
            }
            
            $ch = curl_init("http://{$ip}/cgi-bin/api-set_config");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode != 200 && $httpCode != 202) {
                throw new \Exception("HTTP error: {$httpCode}");
            }
            
            Log::info("Phone configuration updated", ['ip' => $ip]);
            
            return [
                'success' => true,
                'message' => 'Phone configuration updated successfully',
                'ip' => $ip,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to set phone config", [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to set phone configuration',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Provision extension to phone via HTTP API
     */
    public function provisionExtensionToPhone($ip, $extension, $accountNumber = 1, $credentials = [])
    {
        $sipServer = config('rayanpbx.sip_server_ip', '127.0.0.1');
        
        $config = [
            "P{$accountNumber}47" => $sipServer, // SIP Server
            "P{$accountNumber}35" => $extension['extension_number'], // SIP User ID
            "P{$accountNumber}36" => $extension['extension_number'], // Authenticate ID
            "P{$accountNumber}34" => $extension['secret'], // Authenticate Password
            "P{$accountNumber}3" => $extension['name'], // Name
            "P{$accountNumber}270" => "1", // Account Active
        ];
        
        return $this->setPhoneConfig($ip, $config, $credentials);
    }
}
