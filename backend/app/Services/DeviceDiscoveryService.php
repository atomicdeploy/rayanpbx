<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Device Discovery Service
 * 
 * Multi-protocol device discovery for VoIP phones, softphones, and gateways
 * Supports: SSDP (UPnP), mDNS/Bonjour, Asterisk AMI, and network scanning
 */
class DeviceDiscoveryService
{
    private AsteriskStatusService $asteriskStatus;
    
    public function __construct(AsteriskStatusService $asteriskStatus)
    {
        $this->asteriskStatus = $asteriskStatus;
    }
    
    /**
     * Discover devices using all available methods
     */
    public function discoverAll(string $network = null): array
    {
        $devices = [];
        
        // 1. Discover via SSDP (UPnP)
        $ssdpDevices = $this->discoverViaSS DP();
        $devices = array_merge($devices, $ssdpDevices);
        
        // 2. Discover via mDNS/Bonjour
        $mdnsDevices = $this->discoverViaMDNS();
        $devices = array_merge($devices, $mdnsDevices);
        
        // 3. Get registered devices from Asterisk
        $asteriskDevices = $this->discoverViaAsterisk();
        $devices = array_merge($devices, $asteriskDevices);
        
        // 4. Network scan for SIP devices (if network specified)
        if ($network) {
            $scannedDevices = $this->scanNetwork($network);
            $devices = array_merge($devices, $scannedDevices);
        }
        
        // Deduplicate and enrich
        $devices = $this->deduplicateDevices($devices);
        $devices = $this->enrichDevices($devices);
        
        // Cache results
        Cache::put('device_discovery:all', $devices, 300);
        
        return $devices;
    }
    
    /**
     * Discover devices using SSDP (Simple Service Discovery Protocol)
     */
    public function discoverViaSS DP(): array
    {
        $devices = [];
        
        try {
            // Create UDP socket
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$socket) {
                return [];
            }
            
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 3, 'usec' => 0]);
            
            // SSDP M-SEARCH message
            $message = "M-SEARCH * HTTP/1.1\r\n";
            $message .= "HOST: 239.255.255.250:1900\r\n";
            $message .= "MAN: \"ssdp:discover\"\r\n";
            $message .= "MX: 3\r\n";
            $message .= "ST: urn:schemas-upnp-org:device:VoIPPhone:1\r\n";
            $message .= "\r\n";
            
            // Send M-SEARCH
            socket_sendto($socket, $message, strlen($message), 0, '239.255.255.250', 1900);
            
            // Receive responses
            $responses = [];
            while (true) {
                $from = '';
                $port = 0;
                $buf = '';
                
                $bytes = @socket_recvfrom($socket, $buf, 2048, 0, $from, $port);
                if ($bytes === false) {
                    break;
                }
                
                $responses[] = [
                    'data' => $buf,
                    'ip' => $from,
                    'port' => $port
                ];
            }
            
            socket_close($socket);
            
            // Parse responses
            foreach ($responses as $response) {
                $device = $this->parseSSDPResponse($response['data'], $response['ip']);
                if ($device) {
                    $devices[] = $device;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("SSDP discovery failed", ['error' => $e->getMessage()]);
        }
        
        return $devices;
    }
    
    /**
     * Discover devices using mDNS/Bonjour
     */
    public function discoverViaMDNS(): array
    {
        $devices = [];
        
        try {
            // Use dns-sd or avahi-browse if available
            if (command_exists('dns-sd')) {
                $output = shell_exec('timeout 5 dns-sd -B _sip._udp . 2>/dev/null');
            } elseif (command_exists('avahi-browse')) {
                $output = shell_exec('timeout 5 avahi-browse -t _sip._udp 2>/dev/null');
            } else {
                return [];
            }
            
            if ($output) {
                $lines = explode("\n", $output);
                foreach ($lines as $line) {
                    $device = $this->parseMDNSResponse($line);
                    if ($device) {
                        $devices[] = $device;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("mDNS discovery failed", ['error' => $e->getMessage()]);
        }
        
        return $devices;
    }
    
    /**
     * Discover registered devices from Asterisk
     */
    public function discoverViaAsterisk(): array
    {
        $devices = [];
        
        try {
            // Get all registered endpoints
            $endpoints = $this->asteriskStatus->getAllEndpoints();
            
            foreach ($endpoints as $endpoint) {
                $contact = $endpoint['contact'] ?? '';
                
                if (preg_match('/sip:([^@]+)@([^:;]+):?(\d+)?/', $contact, $matches)) {
                    $user = $matches[1];
                    $ip = $matches[2];
                    $port = $matches[3] ?? 5060;
                    
                    $devices[] = [
                        'id' => "asterisk_{$endpoint['name']}",
                        'name' => $endpoint['name'],
                        'type' => 'sip_phone',
                        'discovery_method' => 'asterisk',
                        'ip_address' => $ip,
                        'port' => $port,
                        'sip_user' => $user,
                        'status' => $endpoint['device_state'] === 'not_inuse' ? 'registered' : $endpoint['device_state'],
                        'user_agent' => $endpoint['user_agent'] ?? 'Unknown',
                        'codecs' => $endpoint['codecs'] ?? [],
                        'last_seen' => now()->toDateTimeString(),
                    ];
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Asterisk discovery failed", ['error' => $e->getMessage()]);
        }
        
        return $devices;
    }
    
    /**
     * Scan network for SIP devices
     */
    public function scanNetwork(string $network): array
    {
        $devices = [];
        
        try {
            // Use nmap if available
            if (!command_exists('nmap')) {
                Log::warning("nmap not installed, network scan skipped");
                return [];
            }
            
            // Scan for SIP ports (5060/5061)
            $output = shell_exec("nmap -sV -p 5060,5061 --open {$network} 2>/dev/null");
            
            if ($output) {
                $devices = $this->parseNmapOutput($output);
            }
            
        } catch (\Exception $e) {
            Log::error("Network scan failed", ['error' => $e->getMessage()]);
        }
        
        return $devices;
    }
    
    /**
     * Query device via SIP OPTIONS
     */
    public function queryViaSIPOptions(string $ipAddress, int $port = 5060): ?array
    {
        try {
            // Send SIP OPTIONS request
            $socket = fsockopen("udp://{$ipAddress}", $port, $errno, $errstr, 2);
            if (!$socket) {
                return null;
            }
            
            $callId = uniqid();
            $request = "OPTIONS sip:{$ipAddress}:{$port} SIP/2.0\r\n";
            $request .= "Via: SIP/2.0/UDP " . gethostbyname(gethostname()) . ":5060;branch=z9hG4bK" . substr(md5(rand()), 0, 10) . "\r\n";
            $request .= "From: <sip:rayanpbx@" . gethostbyname(gethostname()) . ">;tag=" . substr(md5(rand()), 0, 10) . "\r\n";
            $request .= "To: <sip:{$ipAddress}:{$port}>\r\n";
            $request .= "Call-ID: {$callId}@rayanpbx\r\n";
            $request .= "CSeq: 1 OPTIONS\r\n";
            $request .= "Contact: <sip:rayanpbx@" . gethostbyname(gethostname()) . ":5060>\r\n";
            $request .= "Accept: application/sdp\r\n";
            $request .= "Content-Length: 0\r\n\r\n";
            
            fwrite($socket, $request);
            
            stream_set_timeout($socket, 2);
            $response = fread($socket, 4096);
            fclose($socket);
            
            if ($response) {
                return $this->parseSIPResponse($response, $ipAddress, $port);
            }
            
        } catch (\Exception $e) {
            Log::error("SIP OPTIONS query failed", [
                'ip' => $ipAddress,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Parse SSDP response
     */
    private function parseSSDPResponse(string $data, string $ip): ?array
    {
        $headers = [];
        $lines = explode("\r\n", $data);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        
        if (isset($headers['location'])) {
            return [
                'id' => 'ssdp_' . md5($ip),
                'name' => $headers['server'] ?? 'Unknown Device',
                'type' => 'upnp_device',
                'discovery_method' => 'ssdp',
                'ip_address' => $ip,
                'location' => $headers['location'],
                'server' => $headers['server'] ?? 'Unknown',
                'usn' => $headers['usn'] ?? '',
                'last_seen' => now()->toDateTimeString(),
            ];
        }
        
        return null;
    }
    
    /**
     * Parse mDNS response
     */
    private function parseMDNSResponse(string $line): ?array
    {
        // Parse mDNS output format
        if (preg_match('/([^\s]+)\s+_sip\._udp/', $line, $matches)) {
            $hostname = $matches[1];
            
            return [
                'id' => 'mdns_' . md5($hostname),
                'name' => $hostname,
                'type' => 'sip_service',
                'discovery_method' => 'mdns',
                'hostname' => $hostname,
                'service' => '_sip._udp',
                'last_seen' => now()->toDateTimeString(),
            ];
        }
        
        return null;
    }
    
    /**
     * Parse nmap output
     */
    private function parseNmapOutput(string $output): array
    {
        $devices = [];
        $lines = explode("\n", $output);
        $currentIp = null;
        
        foreach ($lines as $line) {
            if (preg_match('/Nmap scan report for ([^\s]+)/', $line, $matches)) {
                $currentIp = $matches[1];
            } elseif ($currentIp && preg_match('/(\d+)\/tcp\s+open\s+sip/', $line, $matches)) {
                $port = $matches[1];
                
                $devices[] = [
                    'id' => 'nmap_' . md5($currentIp . $port),
                    'name' => $currentIp,
                    'type' => 'sip_device',
                    'discovery_method' => 'nmap',
                    'ip_address' => $currentIp,
                    'port' => $port,
                    'last_seen' => now()->toDateTimeString(),
                ];
            }
        }
        
        return $devices;
    }
    
    /**
     * Parse SIP OPTIONS response
     */
    private function parseSIPResponse(string $response, string $ip, int $port): array
    {
        $headers = [];
        $lines = explode("\r\n", $response);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        
        return [
            'id' => 'sip_' . md5($ip . $port),
            'name' => $headers['user-agent'] ?? $ip,
            'type' => 'sip_phone',
            'discovery_method' => 'sip_options',
            'ip_address' => $ip,
            'port' => $port,
            'user_agent' => $headers['user-agent'] ?? 'Unknown',
            'allow' => $headers['allow'] ?? '',
            'supported' => $headers['supported'] ?? '',
            'last_seen' => now()->toDateTimeString(),
        ];
    }
    
    /**
     * Deduplicate devices found via multiple methods
     */
    private function deduplicateDevices(array $devices): array
    {
        $unique = [];
        $seen = [];
        
        foreach ($devices as $device) {
            $key = ($device['ip_address'] ?? '') . ':' . ($device['port'] ?? '');
            
            if (!isset($seen[$key])) {
                $unique[] = $device;
                $seen[$key] = true;
            } else {
                // Merge information from multiple discovery methods
                foreach ($unique as &$existing) {
                    $existingKey = ($existing['ip_address'] ?? '') . ':' . ($existing['port'] ?? '');
                    if ($existingKey === $key) {
                        // Merge arrays
                        $existing = array_merge($existing, array_filter($device));
                        break;
                    }
                }
            }
        }
        
        return $unique;
    }
    
    /**
     * Enrich devices with additional information
     */
    private function enrichDevices(array $devices): array
    {
        foreach ($devices as &$device) {
            // Get vendor from MAC address (if available)
            if (isset($device['mac_address'])) {
                $device['vendor'] = $this->getMacVendor($device['mac_address']);
            }
            
            // Identify device type from User-Agent
            if (isset($device['user_agent'])) {
                $device['device_type'] = $this->identifyDeviceType($device['user_agent']);
            }
            
            // Query via SIP OPTIONS if only IP is known
            if (isset($device['ip_address']) && !isset($device['user_agent']) && $device['discovery_method'] !== 'sip_options') {
                $sipInfo = $this->queryViaSIPOptions($device['ip_address'], $device['port'] ?? 5060);
                if ($sipInfo) {
                    $device = array_merge($device, $sipInfo);
                }
            }
        }
        
        return $devices;
    }
    
    /**
     * Get vendor from MAC address
     */
    private function getMacVendor(string $mac): string
    {
        // Common VoIP vendors by MAC prefix
        $vendors = [
            '00:0B:82' => 'GrandStream',
            '00:15:65' => 'Yealink',
            '00:04:F2' => 'Polycom',
            '00:1E:C2' => 'Cisco',
            '00:50:C2' => 'IEEE',
            '00:90:7A' => 'Mitel',
        ];
        
        $prefix = strtoupper(substr($mac, 0, 8));
        return $vendors[$prefix] ?? 'Unknown';
    }
    
    /**
     * Identify device type from User-Agent
     */
    private function identifyDeviceType(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        
        if (strpos($ua, 'grandstream') !== false) return 'GrandStream Phone';
        if (strpos($ua, 'yealink') !== false) return 'Yealink Phone';
        if (strpos($ua, 'polycom') !== false) return 'Polycom Phone';
        if (strpos($ua, 'cisco') !== false) return 'Cisco Phone';
        if (strpos($ua, 'linphone') !== false) return 'Linphone Softphone';
        if (strpos($ua, 'zoiper') !== false) return 'Zoiper Softphone';
        if (strpos($ua, 'microsip') !== false) return 'MicroSIP Softphone';
        if (strpos($ua, 'x-lite') !== false) return 'X-Lite Softphone';
        if (strpos($ua, 'bria') !== false) return 'Bria Softphone';
        
        return 'SIP Device';
    }
    
    /**
     * Get cached devices
     */
    public function getCachedDevices(): array
    {
        return Cache::get('device_discovery:all', []);
    }
}

/**
 * Helper function to check if command exists
 */
function command_exists(string $command): bool
{
    $return = shell_exec("which {$command}");
    return !empty($return);
}
