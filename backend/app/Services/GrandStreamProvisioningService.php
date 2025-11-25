<?php

namespace App\Services;

use App\Helpers\GrandStreamActionUrls;
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
    
    /**
     * Unified HTTP client for phone communication
     */
    protected HttpClientService $httpClient;
  
    /**
     * Regex pattern to match GrandStream phone models
     * GrandStream models start with: GXP, GRP, GXV, DP, WP, GAC, or HT
     */
    protected const GRANDSTREAM_MODEL_PATTERN = '/\b(gxp|grp|gxv|dp|wp|gac|ht)\d+[a-z0-9]*/i';

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
     * Create a new GrandStreamProvisioningService instance
     *
     * @param  HttpClientService|null  $httpClient  Optional HTTP client for dependency injection/testing
     */
    public function __construct(?HttpClientService $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClientService;
    }

    /**
     * Generate provisioning configuration for a phone
     */
    public function generateConfig($mac, $extension, $options = [])
    {
        $model = $options['model'] ?? 'GXP1628';

        if (! isset($this->supportedModels[$model])) {
            throw new \Exception("Unsupported phone model: $model");
        }

        $config = $this->getBaseConfig($model);
        $config = $this->addExtensionConfig($config, $extension, $options);
        $config = $this->addNetworkConfig($config, $options);
        $config = $this->addBLFConfig($config, $options);
        $config = $this->addActionUrlConfig($config, $options);

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
     * Add Action URL configuration for phone events
     */
    protected function addActionUrlConfig($config, $options = [])
    {
        $config['action_urls'] = GrandStreamActionUrls::getAllActionUrls();

        return $config;
    }

    /**
     * Get Action URL configuration for phones
     */
    public function getActionUrlConfig()
    {
        return GrandStreamActionUrls::getActionUrlConfig();
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
            if (! $config['network']['dhcp']) {
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

        // Action URL settings
        if (isset($config['action_urls'])) {
            $xml .= "    <!-- Action URL Configuration -->\n";
            $actionUrlConfig = $this->getActionUrlConfig();
            $pValues = $actionUrlConfig['p_values'];

            foreach ($config['action_urls'] as $event => $url) {
                if (isset($pValues[$event])) {
                    $pValue = $pValues[$event];
                    $escapedUrl = htmlspecialchars($url, ENT_XML1, 'UTF-8');
                    $xml .= "    <{$pValue}>{$escapedUrl}</{$pValue}>\n";
                }
            }
        }

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
            Log::warning('LLDP discovery failed: '.$e->getMessage());
        }
        
        // Try ARP table discovery
        try {
            $arpDevices = $this->discoverViaARP();
            $devices = array_merge($devices, $arpDevices);
        } catch (\Exception $e) {
            Log::warning("ARP discovery failed: " . $e->getMessage());
        }
        
        // Fallback to nmap scanning
        try {
            $nmapDevices = $this->discoverViaNmap($network);
            $devices = array_merge($devices, $nmapDevices);
        } catch (\Exception $e) {
            Log::warning('Nmap discovery failed: '.$e->getMessage());
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
     * Runs all available lldpctl formats and merges results for maximum data
     */
    protected function discoverViaLLDP()
    {
        $allDevices = [];
        
        // Try json0 format first (most structured and verbose, easiest to parse)
        $devices = [];

        // Try lldpctl command (requires lldpd package)
        $output = [];
        $returnCode = 0;
        exec('lldpctl -f json0 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            $parsedDevices = $this->parseLLDPCtlJson0(implode("\n", $output));
            if (!empty($parsedDevices)) {
                $allDevices = array_merge($allDevices, $parsedDevices);
            }
        }
        
        // Try plain format (default, human-readable with good data)
        $output = [];
        exec('lldpctl -f plain 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            $parsedDevices = $this->parseLLDPCtlPlain(implode("\n", $output));
            if (!empty($parsedDevices)) {
                $allDevices = array_merge($allDevices, $parsedDevices);
            }
        }
        
        // Try json format as fallback
        $output = [];
        exec('lldpctl -f json 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            $parsedDevices = $this->parseLLDPCtlJson(implode("\n", $output));
            if (!empty($parsedDevices)) {
                $allDevices = array_merge($allDevices, $parsedDevices);
            }
        }
        
        // NOTE: lldpcli show neighbors is disabled by default
        // It provides similar data to plain format but with different parsing
        // Uncomment below if needed:
        // $output = [];
        // exec('lldpcli show neighbors 2>&1', $output, $returnCode);
        // if ($returnCode === 0) {
        //     $parsedDevices = $this->parseLLDPCliShowNeighbors(implode("\n", $output));
        //     if (!empty($parsedDevices)) {
        //         $allDevices = array_merge($allDevices, $parsedDevices);
        //     }
        // }
        
        // Fallback to keyvalue format
        $output = [];
        exec('lldpctl -f keyvalue 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            $parsedDevices = $this->parseLLDPCtlOutput(implode("\n", $output));
            if (!empty($parsedDevices)) {
                $allDevices = array_merge($allDevices, $parsedDevices);
            }
        }
        
        if (empty($allDevices)) {
            throw new \Exception("LLDP discovery requires lldpd to be installed");
        }
        
        // Deduplicate and merge device data by MAC address
        return $this->mergeDevicesByMAC($allDevices);
    }
    
    /**
     * Parse lldpctl -f json0 output (most verbose JSON format)
     * This format has consistent array structure regardless of neighbor count
     */
    protected function parseLLDPCtlJson0($output)
    {
        $devices = [];
        
        $data = json_decode($output, true);
        if (!$data || !isset($data['lldp'])) {
            return $devices;
        }
        
        // json0 wraps lldp in an array
        $lldpArray = is_array($data['lldp']) ? $data['lldp'] : [$data['lldp']];
        
        foreach ($lldpArray as $lldp) {
            if (!isset($lldp['interface'])) {
                continue;
            }
            
            foreach ($lldp['interface'] as $interface) {
                $device = [
                    'discovery_type' => 'lldp',
                    'last_seen' => now()->toISOString(),
                    'capabilities' => [],
                ];
                
                // Extract interface name
                $interfaceName = $interface['name'] ?? '';
                
                // Parse chassis info
                if (isset($interface['chassis']) && is_array($interface['chassis'])) {
                    foreach ($interface['chassis'] as $chassis) {
                        // ChassisID
                        if (isset($chassis['id']) && is_array($chassis['id'])) {
                            foreach ($chassis['id'] as $id) {
                                $type = $id['type'] ?? '';
                                $value = $id['value'] ?? '';
                                if ($type === 'ip') {
                                    $device['ip'] = $value;
                                } elseif ($type === 'mac') {
                                    $device['mac'] = $value;
                                }
                            }
                        }
                        
                        // System name
                        if (isset($chassis['name']) && is_array($chassis['name'])) {
                            foreach ($chassis['name'] as $name) {
                                $device['hostname'] = $name['value'] ?? '';
                            }
                        }
                        
                        // System description
                        if (isset($chassis['descr']) && is_array($chassis['descr'])) {
                            foreach ($chassis['descr'] as $descr) {
                                $vendorModel = $this->parseSystemDescription($descr['value'] ?? '');
                                if (!empty($vendorModel['vendor'])) {
                                    $device['vendor'] = $vendorModel['vendor'];
                                }
                                if (!empty($vendorModel['model'])) {
                                    $device['model'] = $vendorModel['model'];
                                }
                            }
                        }
                        
                        // Capabilities
                        if (isset($chassis['capability']) && is_array($chassis['capability'])) {
                            foreach ($chassis['capability'] as $cap) {
                                if (!empty($cap['type']) && ($cap['enabled'] ?? false)) {
                                    $device['capabilities'][] = $cap['type'];
                                }
                            }
                        }
                    }
                }
                
                // Parse port info
                if (isset($interface['port']) && is_array($interface['port'])) {
                    foreach ($interface['port'] as $port) {
                        if (isset($port['id']) && is_array($port['id'])) {
                            foreach ($port['id'] as $id) {
                                if (($id['type'] ?? '') === 'mac' && empty($device['mac'])) {
                                    $device['mac'] = $id['value'] ?? '';
                                }
                            }
                        }
                        if (isset($port['descr']) && is_array($port['descr'])) {
                            foreach ($port['descr'] as $descr) {
                                $device['port_id'] = $descr['value'] ?? '';
                            }
                        }
                    }
                }
                
                // Parse LLDP-MED inventory for manufacturer/model info
                if (isset($interface['lldp-med']) && is_array($interface['lldp-med'])) {
                    foreach ($interface['lldp-med'] as $med) {
                        if (isset($med['inventory']) && is_array($med['inventory'])) {
                            foreach ($med['inventory'] as $inv) {
                                if (isset($inv['manufacturer']) && is_array($inv['manufacturer'])) {
                                    foreach ($inv['manufacturer'] as $mfg) {
                                        $device['vendor'] = $mfg['value'] ?? '';
                                    }
                                }
                                if (isset($inv['model']) && is_array($inv['model'])) {
                                    foreach ($inv['model'] as $mdl) {
                                        $device['model'] = $mdl['value'] ?? '';
                                    }
                                }
                                if (isset($inv['serial']) && is_array($inv['serial'])) {
                                    foreach ($inv['serial'] as $srl) {
                                        $device['serial'] = $srl['value'] ?? '';
                                    }
                                }
                                if (isset($inv['software']) && is_array($inv['software'])) {
                                    foreach ($inv['software'] as $sw) {
                                        $device['software_version'] = $sw['value'] ?? '';
                                    }
                                }
                                if (isset($inv['firmware']) && is_array($inv['firmware'])) {
                                    foreach ($inv['firmware'] as $fw) {
                                        $device['firmware_version'] = $fw['value'] ?? '';
                                    }
                                }
                                if (isset($inv['hardware']) && is_array($inv['hardware'])) {
                                    foreach ($inv['hardware'] as $hw) {
                                        $device['hardware_version'] = $hw['value'] ?? '';
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Only add if we have useful info and it's a VoIP phone
                if ($this->isVoIPPhone($device) && (!empty($device['mac']) || !empty($device['ip']))) {
                    $devices[] = $device;
                }
            }
        }
        
        if ($returnCode !== 0) {
            throw new \Exception('LLDP discovery requires lldpd to be installed');
        }

        $devices = $this->parseLLDPCtlOutput(implode("\n", $output));

        return $devices;
    }

    /**
     * Parse lldpctl -f json output
     */
    protected function parseLLDPCtlJson($output)
    {
        $devices = [];
        
        $data = json_decode($output, true);
        if (!$data || !isset($data['lldp']['interface'])) {
            return $devices;
        }
        
        foreach ($data['lldp']['interface'] as $interfaceData) {
            foreach ($interfaceData as $interfaceName => $interface) {
                $device = [
                    'discovery_type' => 'lldp',
                    'last_seen' => now()->toISOString(),
                    'capabilities' => [],
                ];
                
                // Parse chassis info (can be keyed by hostname)
                if (isset($interface['chassis'])) {
                    foreach ($interface['chassis'] as $chassisName => $chassis) {
                        // ChassisID
                        if (isset($chassis['id'])) {
                            $type = $chassis['id']['type'] ?? '';
                            $value = $chassis['id']['value'] ?? '';
                            if ($type === 'ip') {
                                $device['ip'] = $value;
                            } elseif ($type === 'mac') {
                                $device['mac'] = $value;
                            }
                        }
                        
                        // In json format, the chassis key might be the hostname
                        if (is_string($chassisName) && !is_numeric($chassisName)) {
                            $device['hostname'] = $chassisName;
                        }
                        
                        // System description
                        if (isset($chassis['descr'])) {
                            $vendorModel = $this->parseSystemDescription($chassis['descr']);
                            if (!empty($vendorModel['vendor'])) {
                                $device['vendor'] = $vendorModel['vendor'];
                            }
                            if (!empty($vendorModel['model'])) {
                                $device['model'] = $vendorModel['model'];
                            }
                        }
                        
                        // Capabilities
                        if (isset($chassis['capability']) && is_array($chassis['capability'])) {
                            foreach ($chassis['capability'] as $cap) {
                                if (!empty($cap['type']) && ($cap['enabled'] ?? false)) {
                                    $device['capabilities'][] = $cap['type'];
                                }
                            }
                        }
                    }
                }
                
                // Parse port info
                if (isset($interface['port'])) {
                    if (isset($interface['port']['id'])) {
                        if (($interface['port']['id']['type'] ?? '') === 'mac' && empty($device['mac'])) {
                            $device['mac'] = $interface['port']['id']['value'] ?? '';
                        }
                    }
                    if (isset($interface['port']['descr'])) {
                        $device['port_id'] = $interface['port']['descr'];
                    }
                }
                
                // Parse LLDP-MED inventory
                if (isset($interface['lldp-med']['inventory'])) {
                    $inv = $interface['lldp-med']['inventory'];
                    if (isset($inv['manufacturer'])) {
                        $device['vendor'] = $inv['manufacturer'];
                    }
                    if (isset($inv['model'])) {
                        $device['model'] = $inv['model'];
                    }
                    if (isset($inv['serial'])) {
                        $device['serial'] = $inv['serial'];
                    }
                    if (isset($inv['software'])) {
                        $device['software_version'] = $inv['software'];
                    }
                    if (isset($inv['firmware'])) {
                        $device['firmware_version'] = $inv['firmware'];
                    }
                    if (isset($inv['hardware'])) {
                        $device['hardware_version'] = $inv['hardware'];
                    }
                }
                
                // Only add if we have useful info and it's a VoIP phone
                if ($this->isVoIPPhone($device) && (!empty($device['mac']) || !empty($device['ip']))) {
                    $devices[] = $device;
                }
            }
        }
        
        return $devices;
    }
    
    /**
     * Parse lldpctl -f plain output (human-readable format, same as default)
     * This is similar to parseLLDPCliShowNeighbors but may have slight formatting differences
     */
    protected function parseLLDPCtlPlain($output)
    {
        // Plain format is very similar to lldpcli show neighbors
        return $this->parseLLDPCliShowNeighbors($output);
    }
    
    /**
     * Merge devices by MAC address, combining data from multiple sources
     */
    protected function mergeDevicesByMAC($devices)
    {
        $merged = [];
        
        foreach ($devices as $device) {
            $key = $device['mac'] ?? $device['ip'] ?? uniqid();
            
            if (!isset($merged[$key])) {
                $merged[$key] = $device;
            } else {
                // Merge data, preferring non-empty values
                foreach ($device as $field => $value) {
                    if (!empty($value) && (empty($merged[$key][$field]) || $field === 'capabilities')) {
                        if ($field === 'capabilities') {
                            // Merge capabilities arrays
                            $merged[$key][$field] = array_unique(array_merge(
                                $merged[$key][$field] ?? [],
                                is_array($value) ? $value : [$value]
                            ));
                        } else {
                            $merged[$key][$field] = $value;
                        }
                    }
                }
            }
        }
        
        return array_values($merged);
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

            [$key, $value] = $parts;

            // Extract interface name
            if (strpos($key, 'lldp.') === 0) {
                preg_match('/lldp\.([^.]+)\./', $key, $matches);
                if (! empty($matches[1])) {
                    $currentInterface = $matches[1];
                }
            }

            if ($currentInterface === null) {
                continue;
            }

            // Initialize phone entry if needed
            if (! isset($phoneMap[$currentInterface])) {
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
     * Parse lldpcli show neighbors human-readable output
     * 
     * Example format:
     * -------------------------------------------------------------------------------
     * LLDP neighbors:
     * -------------------------------------------------------------------------------
     * Interface:    eno1, via: LLDP, RID: 1, Time: 0 day, 21:21:23
     *   Chassis:
     *     ChassisID:    ip 172.20.6.150
     *     SysName:      GXP1630_ec:74:d7:2f:7e:a2
     *     SysDescr:     GXP1630 1.0.7.64
     *     Capability:   Bridge, on
     *     Capability:   Tel, on
     *   Port:
     *     PortID:       mac ec:74:d7:2f:7e:a2
     *     PortDescr:    eth0
     *     TTL:          120
     */
    protected function parseLLDPCliShowNeighbors($output)
    {
        $devices = [];
        $currentPhone = null;
        
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Skip empty lines and separators
            if (empty($trimmed) || strpos($trimmed, '---') === 0 || $trimmed === 'LLDP neighbors:') {
                continue;
            }
            
            // New interface/neighbor block
            if (strpos($trimmed, 'Interface:') === 0) {
                // Save previous phone if it exists and is a VoIP phone
                if ($currentPhone !== null && $this->isVoIPPhone($currentPhone)) {
                    $devices[] = $currentPhone;
                }
                
                $currentPhone = [
                    'discovery_type' => 'lldp',
                    'last_seen' => now()->toISOString(),
                    'capabilities' => [],
                ];
                continue;
            }
            
            if ($currentPhone === null) {
                continue;
            }
            
            // Parse ChassisID - can be "ip X.X.X.X" or "mac XX:XX:XX:XX:XX:XX"
            if (strpos($trimmed, 'ChassisID:') === 0) {
                $value = trim(substr($trimmed, strlen('ChassisID:')));
                
                if (strpos($value, 'ip ') === 0) {
                    $currentPhone['ip'] = trim(substr($value, 3));
                } elseif (strpos($value, 'mac ') === 0) {
                    $currentPhone['mac'] = trim(substr($value, 4));
                }
                continue;
            }
            
            // Parse SysName - e.g., "GXP1630_ec:74:d7:2f:7e:a2"
            if (strpos($trimmed, 'SysName:') === 0) {
                $value = trim(substr($trimmed, strlen('SysName:')));
                $currentPhone['hostname'] = $value;
                
                // Try to extract vendor/model from SysName
                if (empty($currentPhone['vendor']) || empty($currentPhone['model'])) {
                    $vendorModel = $this->parseSystemDescription($value);
                    if (!empty($vendorModel['vendor'])) {
                        $currentPhone['vendor'] = $vendorModel['vendor'];
                    }
                    if (!empty($vendorModel['model'])) {
                        $currentPhone['model'] = $vendorModel['model'];
                    }
                }
                continue;
            }
            
            // Parse SysDescr - e.g., "GXP1630 1.0.7.64"
            if (strpos($trimmed, 'SysDescr:') === 0) {
                $value = trim(substr($trimmed, strlen('SysDescr:')));
                $vendorModel = $this->parseSystemDescription($value);
                if (!empty($vendorModel['vendor'])) {
                    $currentPhone['vendor'] = $vendorModel['vendor'];
                }
                if (!empty($vendorModel['model'])) {
                    $currentPhone['model'] = $vendorModel['model'];
                }
                continue;
            }
            
            // Parse Capability - e.g., "Bridge, on" or "Tel, on"
            if (strpos($trimmed, 'Capability:') === 0) {
                $value = trim(substr($trimmed, strlen('Capability:')));
                $parts = explode(',', $value);
                if (count($parts) >= 2 && trim($parts[1]) === 'on') {
                    $currentPhone['capabilities'][] = trim($parts[0]);
                }
                continue;
            }
            
            // Parse PortID - e.g., "mac ec:74:d7:2f:7e:a2"
            if (strpos($trimmed, 'PortID:') === 0) {
                $value = trim(substr($trimmed, strlen('PortID:')));
                if (strpos($value, 'mac ') === 0 && empty($currentPhone['mac'])) {
                    $currentPhone['mac'] = trim(substr($value, 4));
                }
                $currentPhone['port_id'] = $value;
                continue;
            }
            
            // Parse PortDescr - e.g., "eth0"
            if (strpos($trimmed, 'PortDescr:') === 0) {
                if (empty($currentPhone['port_id'])) {
                    $currentPhone['port_id'] = trim(substr($trimmed, strlen('PortDescr:')));
                }
                continue;
            }
        }
        
        // Don't forget the last phone
        if ($currentPhone !== null && $this->isVoIPPhone($currentPhone)) {
            $devices[] = $currentPhone;
        }
        
        return $devices;
    }
    
    /**
     * Discover devices from ARP table
     * ARP table contains IP to MAC mappings for recently communicated hosts
     */
    protected function discoverViaARP()
    {
        $devices = [];
        
        $output = [];
        $returnCode = 0;
        exec('arp -a 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception("ARP command failed");
        }
        
        return $this->parseARPOutput(implode("\n", $output));
    }
    
    /**
     * Parse arp -a output
     * 
     * Example format:
     * ? (172.20.4.126) at b0:6e:bf:c0:08:1d [ether] on eno1
     * _gateway (172.20.0.10) at 08:55:31:32:d1:ec [ether] on eno1
     */
    protected function parseARPOutput($output)
    {
        $devices = [];
        
        $lines = explode("\n", $output);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Parse ARP entry: hostname (IP) at MAC [type] on interface
            // or: ? (IP) at MAC [type] on interface (when hostname unknown)
            
            // Extract IP address from parentheses
            if (!preg_match('/\(([0-9.]+)\)/', $line, $ipMatches)) {
                continue;
            }
            $ip = $ipMatches[1];
            
            // Validate IP address
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            
            // Extract MAC address after "at "
            if (!preg_match('/at\s+([0-9a-fA-F:.-]+)/', $line, $macMatches)) {
                continue;
            }
            $mac = $macMatches[1];
            
            // Skip incomplete entries (shown as <incomplete>)
            if (strpos($mac, '<') !== false || strpos($mac, '>') !== false) {
                continue;
            }
            
            // Normalize MAC to lowercase with colons
            $mac = strtolower(str_replace('-', ':', $mac));
            
            // Extract hostname (text before the parenthesis)
            $hostname = '';
            $parenPos = strpos($line, '(');
            if ($parenPos > 0) {
                $hostname = trim(substr($line, 0, $parenPos));
                if ($hostname === '?') {
                    $hostname = '';
                }
            }
            
            // Try to detect vendor from MAC address OUI
            $vendor = $this->detectVendorFromMAC($mac);
            
            $devices[] = [
                'ip' => $ip,
                'mac' => $mac,
                'hostname' => $hostname,
                'vendor' => $vendor,
                'discovery_type' => 'arp',
                'last_seen' => now()->toISOString(),
            ];
        }
        
        return $devices;
    }
    
    /**
     * Detect vendor from MAC address OUI (Organizationally Unique Identifier)
     */
    protected function detectVendorFromMAC($mac)
    {
        // Common VoIP phone vendor OUI prefixes
        $ouiPrefixes = [
            '00:0b:82' => 'GrandStream',
            '00:19:15' => 'GrandStream',
            'c0:74:ad' => 'GrandStream',
            'ec:74:d7' => 'GrandStream',
            '00:15:65' => 'Yealink',
            '80:5e:c0' => 'Yealink',
            '00:04:f2' => 'Polycom',
            '64:16:7f' => 'Polycom',
            '00:1e:c2' => 'Cisco',
            '00:50:c2' => 'Cisco',
            '00:04:13' => 'Snom',
            '00:1b:63' => 'Panasonic',
            '0c:38:3e' => 'Fanvil',
        ];
        
        // Normalize MAC and get first 3 octets
        $mac = strtolower(str_replace('-', ':', $mac));
        $parts = explode(':', $mac);
        if (count($parts) >= 3) {
            $oui = implode(':', array_slice($parts, 0, 3));
            if (isset($ouiPrefixes[$oui])) {
                return $ouiPrefixes[$oui];
            }
        }
        
        return '';
    }
    
    /**
     * Discover phones via nmap network scanning
     */
    protected function discoverViaNmap($network)
    {
        $devices = [];

        // Validate network CIDR notation
        if (! $this->isValidCIDR($network)) {
            throw new \Exception('Invalid network CIDR notation');
        }

        // Check if nmap is available
        exec('which nmap 2>&1', $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \Exception('Nmap is not installed');
        }

        // Scan for common VoIP ports
        $output = [];
        $cmd = sprintf(
            'nmap -sS -p 80,443,5060,5061 --open -T4 -oG - %s 2>&1',
            escapeshellarg($network)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('Nmap scan failed');
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
        if (! preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $cidr)) {
            return false;
        }

        [$ip, $mask] = explode('/', $cidr);

        // Validate IP address
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // Validate mask (0-32)
        $mask = (int) $mask;
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
        $descriptionLower = strtolower($description);
        $vendor = '';
        $model = '';
        
        // Check for GrandStream model prefixes first (e.g., "GXP1630 1.0.7.64")
        // GrandStream models start with GXP, GRP, GXV, DP, WP, GAC, or HT
        if (preg_match(self::GRANDSTREAM_MODEL_PATTERN, $description, $matches) || strpos($description, 'grandstream') !== false) {
            $vendor = 'GrandStream';
            $model = strtoupper($matches[0]);
        }
        // Check for explicit GrandStream mention
        elseif (strpos($descriptionLower, 'grandstream') !== false) {
            $vendor = 'GrandStream';
            if (preg_match(self::GRANDSTREAM_MODEL_PATTERN, $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Yealink
        elseif (strpos($descriptionLower, 'yealink') !== false) {
            $vendor = 'Yealink';
            if (preg_match('/sip-t\d+[a-z]*/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Polycom
        elseif (strpos($descriptionLower, 'polycom') !== false) {
            $vendor = 'Polycom';
            if (preg_match('/(soundpoint|vvx\d+[a-z]*)/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Cisco
        elseif (strpos($descriptionLower, 'cisco') !== false) {
            $vendor = 'Cisco';
            if (preg_match('/(cp-\d+[a-z]*|spa\d+[a-z]*)/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Snom
        elseif (strpos($descriptionLower, 'snom') !== false) {
            $vendor = 'Snom';
            if (preg_match('/snom\d+[a-z]*/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Panasonic
        elseif (strpos($descriptionLower, 'panasonic') !== false) {
            $vendor = 'Panasonic';
            if (preg_match('/kx-[\w]+/i', $description, $matches)) {
                $model = strtoupper($matches[0]);
            }
        }
        // Check for Fanvil
        elseif (strpos($descriptionLower, 'fanvil') !== false) {
            $vendor = 'Fanvil';
            if (preg_match('/x\d+[a-z]*/i', $description, $matches)) {
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
            $response = $this->httpClient->localClient(3)->get("http://{$ip}/");

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
        if (! empty($device['vendor'])) {
            $vendorLower = strtolower($device['vendor']);
            foreach ($voipVendors as $v) {
                if (strpos($vendorLower, $v) !== false) {
                    return true;
                }
            }
        }

        // Check hostname
        if (! empty($device['hostname'])) {
            $hostLower = strtolower($device['hostname']);
            foreach ($voipVendors as $v) {
                if (strpos($hostLower, $v) !== false) {
                    return true;
                }
            }
        }

        // Check if model is set
        if (! empty($device['model'])) {
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

            if ($key && ! isset($seen[$key])) {
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

            if (! $output) {
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

                    if (! empty($ip)) {
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
            Log::error('Failed to get registered phones', ['error' => $e->getMessage()]);

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
            $response = $this->httpClient->localClient(2)->head("http://{$ip}/");

            if ($response->successful()) {
                $server = $response->header('Server');
                if ($server) {
                    return $server;
                }
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
            // GrandStream phones expose status API at /cgi-bin/api-sys_operation
            $response = $this->httpClient->withBasicAuth(
                "http://{$ip}/cgi-bin/api-sys_operation",
                $username,
                $password,
                'GET',
                [],
                ['timeout' => 5]
            );

            if ($response->successful()) {
                // Prefer JSON; fall back to XML if necessary
                $data = $response->json();

                if (! $data) {
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
                'message' => 'Failed to get phone status: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Ping a host to check if it's reachable
     */
    public function pingHost($ip, $timeout = 2)
    {
        // Validate IP address
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $output = [];
        $returnCode = 0;

        // Use system ping command
        $cmd = sprintf('ping -c 1 -W %d %s 2>&1', (int) $timeout, escapeshellarg($ip));
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

        if (! $extension) {
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
            'provisioning_url' => config('rayanpbx.provisioning_base_url')."/{$filename}",
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
            $response = $this->httpClient->withBasicAuth(
                "http://{$ip}/cgi-bin/api-sys_operation?request=reboot",
                $username,
                $password,
                'POST',
                [],
                ['timeout' => 10]
            );

            if (! $response->successful() && $response->status() !== 202) {
                throw new \Exception('HTTP error: '.$response->status());
            }

            Log::info('Phone rebooted', ['ip' => $ip]);

            return [
                'success' => true,
                'message' => 'Phone reboot command sent successfully',
                'ip' => $ip,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to reboot phone', [
                'ip' => $ip,
                'error' => $e->getMessage(),
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
            $response = $this->httpClient->withBasicAuth(
                "http://{$ip}/cgi-bin/api-sys_operation?request=factory_reset",
                $username,
                $password,
                'POST',
                [],
                ['timeout' => 10]
            );

            if (! $response->successful() && $response->status() !== 202) {
                throw new \Exception('HTTP error: '.$response->status());
            }

            Log::warning('Phone factory reset', ['ip' => $ip]);

            return [
                'success' => true,
                'message' => 'Phone factory reset command sent successfully',
                'ip' => $ip,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to factory reset phone', [
                'ip' => $ip,
                'error' => $e->getMessage(),
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
            $response = $this->httpClient->withBasicAuth(
                "http://{$ip}/cgi-bin/api-get_config",
                $username,
                $password,
                'GET',
                [],
                ['timeout' => 10]
            );

            if (! $response->successful()) {
                throw new \Exception('HTTP error: '.$response->status());
            }

            // Parse response
            $config = $response->json();
            if (! $config) {
                // Try XML parsing
                $xml = @simplexml_load_string($response->body());
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
            Log::error('Failed to get phone config', [
                'ip' => $ip,
                'error' => $e->getMessage(),
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
            $response = $this->httpClient->client([
                'timeout' => 10,
                'headers' => ['Content-Type' => 'application/json'],
            ])->withBasicAuth($username, $password)
                ->post("http://{$ip}/cgi-bin/api-set_config", $config);

            if (! $response->successful() && $response->status() !== 202) {
                throw new \Exception('HTTP error: '.$response->status());
            }

            Log::info('Phone configuration updated', ['ip' => $ip]);

            return [
                'success' => true,
                'message' => 'Phone configuration updated successfully',
                'ip' => $ip,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to set phone config', [
                'ip' => $ip,
                'error' => $e->getMessage(),
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
            "P{$accountNumber}270" => '1', // Account Active
        ];

        return $this->setPhoneConfig($ip, $config, $credentials);
    }

    /**
     * Check current Action URL configuration on a phone
     * Returns the current values and whether they match expected values
     */
    public function checkActionUrls($ip, $credentials = [])
    {
        $result = $this->getPhoneConfig($ip, $credentials);

        if (! $result['success']) {
            return $result;
        }

        $currentConfig = $result['config'];
        $actionUrlConfig = $this->getActionUrlConfig();
        $pValues = $actionUrlConfig['p_values'];
        $expectedUrls = $actionUrlConfig['action_urls'];

        $actionUrlStatus = [];
        $hasConflicts = false;
        $needsUpdate = false;

        foreach ($pValues as $event => $pValue) {
            $currentValue = $currentConfig[$pValue] ?? '';
            $expectedValue = $expectedUrls[$event] ?? '';

            $status = [
                'event' => $event,
                'p_value' => $pValue,
                'current' => $currentValue,
                'expected' => $expectedValue,
                'matches' => $currentValue === $expectedValue,
            ];

            // Detect conflicts - if current value is not empty and doesn't match expected
            if (! empty($currentValue) && $currentValue !== $expectedValue) {
                $status['conflict'] = true;
                $hasConflicts = true;
            } else {
                $status['conflict'] = false;
            }

            // Needs update if current doesn't match expected
            if ($currentValue !== $expectedValue) {
                $needsUpdate = true;
            }

            $actionUrlStatus[$event] = $status;
        }

        return [
            'success' => true,
            'ip' => $ip,
            'action_urls' => $actionUrlStatus,
            'has_conflicts' => $hasConflicts,
            'needs_update' => $needsUpdate,
            'summary' => [
                'total' => count($pValues),
                'matching' => count(array_filter($actionUrlStatus, fn ($s) => $s['matches'])),
                'conflicts' => count(array_filter($actionUrlStatus, fn ($s) => $s['conflict'])),
            ],
        ];
    }

    /**
     * Update Action URLs on a phone
     *
     * @param  bool  $forceUpdate  If true, overwrites existing non-matching values without confirmation
     */
    public function updateActionUrls($ip, $credentials = [], $forceUpdate = false)
    {
        // First check current status
        $checkResult = $this->checkActionUrls($ip, $credentials);

        if (! $checkResult['success']) {
            return $checkResult;
        }

        // If there are conflicts and force update is not enabled, require confirmation
        if ($checkResult['has_conflicts'] && ! $forceUpdate) {
            return [
                'success' => false,
                'requires_confirmation' => true,
                'ip' => $ip,
                'message' => 'Phone has existing Action URL configuration that differs from expected values. Set forceUpdate to true to overwrite.',
                'conflicts' => array_filter($checkResult['action_urls'], fn ($s) => $s['conflict']),
            ];
        }

        // If no update needed
        if (! $checkResult['needs_update']) {
            return [
                'success' => true,
                'ip' => $ip,
                'message' => 'Action URLs are already configured correctly',
                'updated' => false,
            ];
        }

        // Build configuration to set
        $actionUrlConfig = $this->getActionUrlConfig();
        $pValues = $actionUrlConfig['p_values'];
        $expectedUrls = $actionUrlConfig['action_urls'];

        $configToSet = [];
        foreach ($pValues as $event => $pValue) {
            $configToSet[$pValue] = $expectedUrls[$event];
        }

        // Apply configuration
        $result = $this->setPhoneConfig($ip, $configToSet, $credentials);

        if ($result['success']) {
            Log::info('Action URLs updated on phone', [
                'ip' => $ip,
                'forced' => $forceUpdate,
            ]);
        }

        return [
            'success' => $result['success'],
            'ip' => $ip,
            'message' => $result['success'] ? 'Action URLs updated successfully' : 'Failed to update Action URLs',
            'updated' => $result['success'],
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Provision extension to phone with Action URLs
     * This is a complete provisioning that includes both SIP account and Action URLs
     */
    public function provisionPhoneComplete($ip, $extension, $accountNumber = 1, $credentials = [], $forceActionUrls = false)
    {
        // Provision extension first
        $extensionResult = $this->provisionExtensionToPhone($ip, $extension, $accountNumber, $credentials);

        if (! $extensionResult['success']) {
            return [
                'success' => false,
                'message' => 'Failed to provision extension',
                'extension_error' => $extensionResult['error'] ?? $extensionResult['message'] ?? 'Unknown error',
            ];
        }

        // Update Action URLs
        $actionUrlResult = $this->updateActionUrls($ip, $credentials, $forceActionUrls);

        // Check if Action URL update requires confirmation
        if (isset($actionUrlResult['requires_confirmation']) && $actionUrlResult['requires_confirmation']) {
            return [
                'success' => true, // Extension provisioned successfully
                'ip' => $ip,
                'extension' => $extension['extension_number'],
                'account_number' => $accountNumber,
                'extension_provisioned' => true,
                'action_urls_result' => $actionUrlResult,
            ];
        }

        // Check if Action URL update failed
        if (! $actionUrlResult['success'] && ! isset($actionUrlResult['requires_confirmation'])) {
            return [
                'success' => false,
                'message' => 'Extension provisioned but Action URL update failed',
                'ip' => $ip,
                'extension' => $extension['extension_number'],
                'account_number' => $accountNumber,
                'extension_provisioned' => true,
                'action_urls_result' => $actionUrlResult,
            ];
        }

        return [
            'success' => true,
            'ip' => $ip,
            'extension' => $extension['extension_number'],
            'account_number' => $accountNumber,
            'extension_provisioned' => true,
            'action_urls_result' => $actionUrlResult,
        ];
    }
}
