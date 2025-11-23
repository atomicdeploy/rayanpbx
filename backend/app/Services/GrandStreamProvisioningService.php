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
        // TODO: Implement network scanning using nmap or similar
        // Look for devices with open port 80/443 and GrandStream in HTTP headers
        
        Log::info("Discovering GrandStream phones on network: $network");
        
        return [
            'status' => 'pending',
            'message' => 'Phone discovery not yet implemented',
            'implementation' => 'Will use nmap to scan network for devices with GrandStream signatures',
        ];
    }

    /**
     * Get phone status via HTTP
     */
    public function getPhoneStatus($ip, $credentials = [])
    {
        // TODO: Implement HTTP API call to phone
        // GrandStream phones have web interface at http://phone-ip/
        
        return [
            'status' => 'unknown',
            'message' => 'Phone status retrieval not yet implemented',
            'implementation' => 'Will use HTTP client to fetch phone status from web interface',
        ];
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
}
