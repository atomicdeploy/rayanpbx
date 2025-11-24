<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

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
        Log::info("Discovering phones on network: $network");
        
        $devices = [];
        
        // Try LLDP discovery first
        try {
            $lldpDevices = $this->discoverViaLLDP();
            $devices = array_merge($devices, $lldpDevices);
        } catch (\Exception $e) {
            Log::warning("LLDP discovery failed: " . $e->getMessage());
        }
        
        // Fallback to nmap scanning
        try {
            $nmapDevices = $this->discoverViaNmap($network);
            $devices = array_merge($devices, $nmapDevices);
        } catch (\Exception $e) {
            Log::warning("Nmap discovery failed: " . $e->getMessage());
        }
        
        // Deduplicate by MAC or IP
        $devices = $this->deduplicateDevices($devices);
        
        // Get registered phones from Asterisk
        $registeredPhones = $this->getRegisteredPhonesFromAsterisk();
        
        return [
            'status' => 'success',
            'count' => count($devices),
            'devices' => $devices,
            'phones' => $registeredPhones,
            'network' => $network,
        ];
    }
    
    /**
     * Discover phones via LLDP protocol
     */
    protected function discoverViaLLDP()
    {
        $devices = [];
        
        // Try lldpctl command (requires lldpd package)
        $output = [];
        $returnCode = 0;
        exec('lldpctl -f keyvalue 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception("LLDP discovery requires lldpd to be installed");
        }
        
        $devices = $this->parseLLDPCtlOutput(implode("\n", $output));
        
        return $devices;
    }
    
    /**
     * Parse lldpctl keyvalue output
     */
    protected function parseLLDPCtlOutput($output)
    {
        $devices = [];
        $phoneMap = [];
        
        $lines = explode("\n", $output);
        $currentInterface = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            
            list($key, $value) = $parts;
            
            // Extract interface name
            if (strpos($key, 'lldp.') === 0) {
                preg_match('/lldp\.([^.]+)\./', $key, $matches);
                if (!empty($matches[1])) {
                    $currentInterface = $matches[1];
                }
            }
            
            if ($currentInterface === null) {
                continue;
            }
            
            // Initialize phone entry if needed
            if (!isset($phoneMap[$currentInterface])) {
                $phoneMap[$currentInterface] = [
                    'discovery_type' => 'lldp',
                    'last_seen' => now()->toISOString(),
                ];
            }
            
            $phone = &$phoneMap[$currentInterface];
            
            // Parse LLDP fields
            if (strpos($key, '.chassis.mac') !== false) {
                $phone['mac'] = $value;
            } elseif (strpos($key, '.chassis.name') !== false) {
                $phone['hostname'] = $value;
            } elseif (strpos($key, '.port.descr') !== false) {
                $phone['port_id'] = $value;
            } elseif (strpos($key, '.mgmt-ip') !== false) {
                $phone['ip'] = $value;
            } elseif (strpos($key, '.chassis.descr') !== false) {
                // Parse vendor/model from system description
                $vendorModel = $this->parseSystemDescription($value);
                $phone['vendor'] = $vendorModel['vendor'];
                $phone['model'] = $vendorModel['model'];
            }
        }
        
        // Filter for VoIP phones
        foreach ($phoneMap as $phone) {
            if ($this->isVoIPPhone($phone)) {
                $devices[] = $phone;
            }
        }
        
        return $devices;
    }
    
    /**
     * Discover phones via nmap network scanning
     */
    protected function discoverViaNmap($network)
    {
        $devices = [];
        
        // Validate network CIDR notation
        if (!$this->isValidCIDR($network)) {
            throw new \Exception("Invalid network CIDR notation");
        }
        
        // Check if nmap is available
        exec('which nmap 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \Exception("Nmap is not installed");
        }
        
        // Scan for common VoIP ports
        $output = [];
        $cmd = sprintf(
            'nmap -sS -p 80,443,5060,5061 --open -T4 -oG - %s 2>&1',
            escapeshellarg($network)
        );
        exec($cmd, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception("Nmap scan failed");
        }
        
        $devices = $this->parseNmapOutput(implode("\n", $output));
        
        return $devices;
    }
    
    /**
     * Validate CIDR notation for network address
     */
    protected function isValidCIDR($cidr)
    {
        // Check format: IP/mask
        if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $cidr)) {
            return false;
        }
        
        list($ip, $mask) = explode('/', $cidr);
        
        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        
        // Validate mask (0-32)
        $mask = (int)$mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Parse nmap greppable output
     */
    protected function parseNmapOutput($output)
    {
        $devices = [];
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            if (strpos($line, 'Host:') !== 0) {
                continue;
            }
            
            // Parse: Host: 192.168.1.100 ()  Status: Up
            preg_match('/Host:\s+(\S+)/', $line, $matches);
            if (empty($matches[1])) {
                continue;
            }
            
            $ip = $matches[1];
            
            // Check for VoIP-related ports
            if (strpos($line, '80/open') === false && 
                strpos($line, '443/open') === false && 
                strpos($line, '5060/open') === false) {
                continue;
            }
            
            $device = [
                'ip' => $ip,
                'discovery_type' => 'nmap',
                'last_seen' => now()->toISOString(),
                'online' => true,
            ];
            
            // Try to detect vendor via HTTP
            try {
                $vendorModel = $this->detectVendorViaHTTP($ip);
                $device['vendor'] = $vendorModel['vendor'];
                $device['model'] = $vendorModel['model'] ?? 'Unknown';
            } catch (\Exception $e) {
                // Ignore detection errors
            }
            
            $devices[] = $device;
        }
        
        return $devices;
    }
    
    /**
     * Parse system description to extract vendor and model
     */
    protected function parseSystemDescription($description)
    {
        $description = strtolower($description);
        $vendor = '';
        $model = '';
        
        // Check for GrandStream
        if (strpos($description, 'grandstream') !== false) {
            $vendor = 'GrandStream';
            if (preg_match('/gxp\d+[a-z]*/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Yealink
        elseif (strpos($description, 'yealink') !== false) {
            $vendor = 'Yealink';
            if (preg_match('/sip-t\d+[a-z]*/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Polycom
        elseif (strpos($description, 'polycom') !== false) {
            $vendor = 'Polycom';
            if (preg_match('/(soundpoint|vvx\d+[a-z]*)/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Cisco
        elseif (strpos($description, 'cisco') !== false) {
            $vendor = 'Cisco';
            if (preg_match('/(cp-\d+[a-z]*|spa\d+[a-z]*)/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        
        return ['vendor' => $vendor, 'model' => $model];
    }
    
    /**
     * Detect vendor via HTTP headers/content
     */
    protected function detectVendorViaHTTP($ip)
    {
        try {
            $response = Http::timeout(3)->get("http://{$ip}/");
            
            // Check Server header
            $server = $response->header('Server');
            if ($server && strpos(strtolower($server), 'grandstream') !== false) {
                return ['vendor' => 'GrandStream'];
            }
            if ($server && strpos(strtolower($server), 'yealink') !== false) {
                return ['vendor' => 'Yealink'];
            }
            
            // Check body content
            $body = strtolower($response->body());
            if (strpos($body, 'grandstream') !== false) {
                return ['vendor' => 'GrandStream'];
            }
            if (strpos($body, 'yealink') !== false) {
                return ['vendor' => 'Yealink'];
            }
            if (strpos($body, 'polycom') !== false) {
                return ['vendor' => 'Polycom'];
            }
        } catch (\Exception $e) {
            // HTTP detection failed
        }
        
        return ['vendor' => 'Unknown'];
    }
    
    /**
     * Check if device is likely a VoIP phone
     */
    protected function isVoIPPhone($device)
    {
        $voipVendors = ['grandstream', 'yealink', 'polycom', 'cisco', 'snom', 'panasonic', 'fanvil'];
        
        // Check vendor
        if (!empty($device['vendor'])) {
            $vendorLower = strtolower($device['vendor']);
            foreach ($voipVendors as $v) {
                if (strpos($vendorLower, $v) !== false) {
                    return true;
                }
            }
        }
        
        // Check hostname
        if (!empty($device['hostname'])) {
            $hostLower = strtolower($device['hostname']);
            foreach ($voipVendors as $v) {
                if (strpos($hostLower, $v) !== false) {
                    return true;
                }
            }
        }
        
        // Check if model is set
        if (!empty($device['model'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Deduplicate devices by MAC or IP
     */
    protected function deduplicateDevices($devices)
    {
        $seen = [];
        $result = [];
        
        foreach ($devices as $device) {
            $key = $device['mac'] ?? $device['ip'] ?? null;
            
            if ($key && !isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $device;
            }
        }
        
        return $result;
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
            /*
                // TODO: merge and remove
                $ch = curl_init("http://{$ip}/cgi-bin/api-sys_operation");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            */

            // GrandStream phones expose status API at /cgi-bin/api-sys_operation
            $response = Http::timeout(5)
                ->withBasicAuth($username, $password)
                ->get("http://{$ip}/cgi-bin/api-sys_operation");

            if ($response->successful()) {
                // Prefer JSON; fall back to XML if necessary
                $data = $response->json();

                if (!$data) {
                    $xml = @simplexml_load_string($response->body());
                    $data = $xml ? json_decode(json_encode($xml), true) : [];
                }

                return [
                    'status' => 'online',
                    'ip' => $ip,
                    'model' => $data['model'] ?? 'Unknown',
                    'firmware' => $data['firmware'] ?? 'Unknown',
                    'mac' => $data['mac'] ?? 'Unknown',
                    'uptime' => $data['uptime'] ?? 'Unknown',
                    'registered' => $data['registered'] ?? true,
                    'last_update' => now()->toIso8601String(),
                ];
            }

            // Non-2xx => host might be up but API is not available/authorized
            return [
                'status' => 'reachable',
                'ip' => $ip,
                'message' => 'Phone is reachable but status API not available',
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to get phone status', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'offline',
                'ip' => $ip,
                'status' => 'error',
                'error' => $e->getMessage(),
                'message' => 'Failed to get phone status: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Ping a host to check if it's reachable
     */
    public function pingHost($ip, $timeout = 2)
    {
        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $output = [];
        $returnCode = 0;

        // Use system ping command
        $cmd = sprintf('ping -c 1 -W %d %s 2>&1', (int)$timeout, escapeshellarg($ip));
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Check reachability of multiple phones
     */
    public function checkPhoneReachability($phones)
    {
        foreach ($phones as &$phone) {
            $phone['online'] = $this->pingHost($phone['ip']);
        }

        return $phones;
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
