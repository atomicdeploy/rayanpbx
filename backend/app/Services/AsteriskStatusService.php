<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Enhanced Asterisk Service with real-time status monitoring
 */
class AsteriskStatusService
{
    private string $amiHost;
    private int $amiPort;
    private string $amiUsername;
    private string $amiSecret;
    private $amiSocket = null;

    public function __construct()
    {
        $this->amiHost = config('rayanpbx.asterisk.ami_host', '127.0.0.1');
        $this->amiPort = config('rayanpbx.asterisk.ami_port', 5038);
        $this->amiUsername = config('rayanpbx.asterisk.ami_username', 'admin');
        $this->amiSecret = config('rayanpbx.asterisk.ami_secret', '');
    }

    /**
     * Connect to AMI and keep connection alive
     */
    private function connectAMI()
    {
        if ($this->amiSocket && !feof($this->amiSocket)) {
            return $this->amiSocket;
        }

        try {
            $this->amiSocket = fsockopen($this->amiHost, $this->amiPort, $errno, $errstr, 5);
            
            if (!$this->amiSocket) {
                throw new Exception("Cannot connect to AMI: $errstr ($errno)");
            }

            // Set non-blocking
            stream_set_blocking($this->amiSocket, false);
            
            // Read banner
            sleep(1);
            $this->readResponse();
            
            // Login
            $this->sendAction([
                'Action' => 'Login',
                'Username' => $this->amiUsername,
                'Secret' => $this->amiSecret,
            ]);
            
            $response = $this->readResponse();
            
            if (!stripos($response, 'Success')) {
                throw new Exception("AMI login failed");
            }
            
            return $this->amiSocket;
            
        } catch (Exception $e) {
            Log::error("AMI Connection Error: " . $e->getMessage());
            $this->amiSocket = null;
            return null;
        }
    }

    /**
     * Send AMI action
     */
    private function sendAction(array $action)
    {
        $message = '';
        foreach ($action as $key => $value) {
            $message .= "$key: $value\r\n";
        }
        $message .= "\r\n";
        
        if ($this->amiSocket) {
            fwrite($this->amiSocket, $message);
        }
    }

    /**
     * Read AMI response
     */
    private function readResponse(float $timeout = 2.0): string
    {
        $response = '';
        $start = microtime(true);
        
        stream_set_blocking($this->amiSocket, false);
        
        while ((microtime(true) - $start) < $timeout) {
            $line = fgets($this->amiSocket);
            
            if ($line === false) {
                usleep(10000); // 10ms
                continue;
            }
            
            $response .= $line;
            
            // Check for end of response
            if (trim($line) === '' && strlen($response) > 10) {
                break;
            }
        }
        
        return $response;
    }

    /**
     * Get detailed endpoint status with registration info
     */
    public function getEndpointDetails(string $endpoint): array
    {
        $socket = $this->connectAMI();
        
        if (!$socket) {
            return [
                'endpoint' => $endpoint,
                'registered' => false,
                'status' => 'unknown',
                'error' => 'Cannot connect to AMI',
            ];
        }

        try {
            // Get endpoint status via PJSIPShowEndpoint
            $this->sendAction([
                'Action' => 'PJSIPShowEndpoint',
                'Endpoint' => $endpoint,
            ]);
            
            $response = $this->readResponse(3.0);
            
            return $this->parseEndpointResponse($response, $endpoint);
            
        } catch (Exception $e) {
            Log::error("Get endpoint details error: " . $e->getMessage());
            return [
                'endpoint' => $endpoint,
                'registered' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse endpoint response from AMI
     */
    private function parseEndpointResponse(string $response, string $endpoint): array
    {
        $data = [
            'endpoint' => $endpoint,
            'registered' => false,
            'status' => 'offline',
            'contacts' => [],
            'codecs' => [],
            'device_state' => 'unknown',
            'user_agent' => null,
            'ip_address' => null,
            'port' => null,
            'qualify_timeout' => null,
            'last_qualify_ms' => null,
        ];

        $lines = explode("\n", $response);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Parse key-value pairs
            if (preg_match('/^(\w+):\s*(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                
                switch ($key) {
                    case 'DeviceState':
                        $data['device_state'] = strtolower($value);
                        $data['registered'] = in_array(strtolower($value), ['inuse', 'not_inuse', 'ringing']);
                        $data['status'] = $data['registered'] ? 'registered' : 'offline';
                        break;
                        
                    case 'Contacts':
                        if ($value && $value !== '<none>') {
                            $contacts = explode(',', $value);
                            foreach ($contacts as $contact) {
                                $data['contacts'][] = trim($contact);
                                
                                // Extract IP and port from contact
                                if (preg_match('/sip:.*?@([\d.]+):(\d+)/', $contact, $ipMatches)) {
                                    $data['ip_address'] = $ipMatches[1];
                                    $data['port'] = (int)$ipMatches[2];
                                }
                            }
                        }
                        break;
                        
                    case 'Codecs':
                        if ($value && $value !== '<none>') {
                            $data['codecs'] = array_map('trim', explode(',', $value));
                        }
                        break;
                        
                    case 'RoundtripUsec':
                        $data['last_qualify_ms'] = round((int)$value / 1000, 2);
                        break;
                }
            }
        }
        
        return $data;
    }

    /**
     * Get all registered endpoints
     */
    public function getAllRegisteredEndpoints(): array
    {
        $socket = $this->connectAMI();
        
        if (!$socket) {
            return [];
        }

        try {
            $this->sendAction([
                'Action' => 'PJSIPShowEndpoints',
            ]);
            
            $response = $this->readResponse(5.0);
            
            return $this->parseEndpointsListResponse($response);
            
        } catch (Exception $e) {
            Log::error("Get all endpoints error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse multiple endpoints response
     */
    private function parseEndpointsListResponse(string $response): array
    {
        $endpoints = [];
        $currentEndpoint = null;
        $lines = explode("\n", $response);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (preg_match('/^ObjectName:\s*(.+)$/', $line, $matches)) {
                if ($currentEndpoint) {
                    $endpoints[] = $currentEndpoint;
                }
                
                $currentEndpoint = [
                    'endpoint' => trim($matches[1]),
                    'registered' => false,
                    'status' => 'offline',
                    'device_state' => 'unknown',
                    'contacts' => [],
                ];
                
            } elseif ($currentEndpoint && preg_match('/^(\w+):\s*(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                
                switch ($key) {
                    case 'DeviceState':
                        $currentEndpoint['device_state'] = strtolower($value);
                        $currentEndpoint['registered'] = in_array(strtolower($value), ['inuse', 'not_inuse', 'ringing']);
                        $currentEndpoint['status'] = $currentEndpoint['registered'] ? 'registered' : 'offline';
                        break;
                        
                    case 'Contacts':
                        if ($value && $value !== '<none>') {
                            $currentEndpoint['contacts'] = array_map('trim', explode(',', $value));
                        }
                        break;
                }
            }
        }
        
        if ($currentEndpoint) {
            $endpoints[] = $currentEndpoint;
        }
        
        return $endpoints;
    }

    /**
     * Get codec information for a channel
     */
    public function getChannelCodecInfo(string $channel): array
    {
        $socket = $this->connectAMI();
        
        if (!$socket) {
            return [];
        }

        try {
            $this->sendAction([
                'Action' => 'Command',
                'Command' => "core show channel {$channel}",
            ]);
            
            $response = $this->readResponse(3.0);
            
            return $this->parseChannelCodecInfo($response);
            
        } catch (Exception $e) {
            Log::error("Get channel codec error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse codec information from channel details
     */
    private function parseChannelCodecInfo(string $response): array
    {
        $data = [
            'read_codec' => null,
            'write_codec' => null,
            'read_format' => null,
            'write_format' => null,
            'sample_rate' => null,
            'is_hd' => false,
        ];

        $lines = explode("\n", $response);
        
        foreach ($lines as $line) {
            if (preg_match('/Read Codec:\s*(\w+)/', $line, $matches)) {
                $data['read_codec'] = $matches[1];
            }
            
            if (preg_match('/Write Codec:\s*(\w+)/', $line, $matches)) {
                $data['write_codec'] = $matches[1];
            }
            
            if (preg_match('/Read Format:\s*(\w+)/', $line, $matches)) {
                $data['read_format'] = $matches[1];
            }
            
            if (preg_match('/Write Format:\s*(\w+)/', $line, $matches)) {
                $data['write_format'] = $matches[1];
            }
        }

        // Determine if HD based on codec
        $hdCodecs = ['g722', 'opus', 'silk', 'speex16', 'slin16', 'g722.2'];
        $codec = strtolower($data['read_codec'] ?? $data['write_codec'] ?? '');
        
        $data['is_hd'] = in_array($codec, $hdCodecs);
        
        if ($data['is_hd']) {
            $data['sample_rate'] = $this->getCodecSampleRate($codec);
        } else {
            $data['sample_rate'] = 8000; // Narrowband default
        }

        return $data;
    }

    /**
     * Get sample rate for codec
     */
    private function getCodecSampleRate(string $codec): int
    {
        $rates = [
            'g722' => 16000,
            'g722.2' => 16000,
            'opus' => 48000,
            'silk' => 16000,
            'speex16' => 16000,
            'slin16' => 16000,
            'slin32' => 32000,
            'slin48' => 48000,
        ];

        return $rates[strtolower($codec)] ?? 8000;
    }

    /**
     * Get RTP statistics for a channel
     */
    public function getRTPStats(string $channel): array
    {
        $socket = $this->connectAMI();
        
        if (!$socket) {
            return [];
        }

        try {
            $this->sendAction([
                'Action' => 'Command',
                'Command' => "rtp show stats {$channel}",
            ]);
            
            $response = $this->readResponse(3.0);
            
            return $this->parseRTPStats($response);
            
        } catch (Exception $e) {
            Log::error("Get RTP stats error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse RTP statistics
     */
    private function parseRTPStats(string $response): array
    {
        $data = [
            'ssrc' => null,
            'packets_sent' => 0,
            'packets_received' => 0,
            'packets_lost' => 0,
            'jitter' => 0.0,
            'rtt' => 0.0,
            'packet_loss_percent' => 0.0,
        ];

        $lines = explode("\n", $response);
        
        foreach ($lines as $line) {
            if (preg_match('/Packets Sent:\s*(\d+)/', $line, $matches)) {
                $data['packets_sent'] = (int)$matches[1];
            }
            
            if (preg_match('/Packets Received:\s*(\d+)/', $line, $matches)) {
                $data['packets_received'] = (int)$matches[1];
            }
            
            if (preg_match('/Packets Lost:\s*(\d+)/', $line, $matches)) {
                $data['packets_lost'] = (int)$matches[1];
            }
            
            if (preg_match('/Jitter:\s*([\d.]+)/', $line, $matches)) {
                $data['jitter'] = (float)$matches[1];
            }
            
            if (preg_match('/RTT:\s*([\d.]+)/', $line, $matches)) {
                $data['rtt'] = (float)$matches[1];
            }
        }

        // Calculate packet loss percentage
        $totalExpected = $data['packets_received'] + $data['packets_lost'];
        if ($totalExpected > 0) {
            $data['packet_loss_percent'] = round(($data['packets_lost'] / $totalExpected) * 100, 2);
        }

        return $data;
    }

    /**
     * Get trunk/peer qualify status
     */
    public function getTrunkStatus(string $trunk): array
    {
        $socket = $this->connectAMI();
        
        if (!$socket) {
            return [
                'trunk' => $trunk,
                'reachable' => false,
                'status' => 'unknown',
            ];
        }

        try {
            $this->sendAction([
                'Action' => 'PJSIPShowEndpoint',
                'Endpoint' => $trunk,
            ]);
            
            $response = $this->readResponse(3.0);
            
            $data = $this->parseEndpointResponse($response, $trunk);
            
            // Additional trunk-specific parsing
            $data['reachable'] = $data['registered'];
            $data['latency_ms'] = $data['last_qualify_ms'];
            
            return $data;
            
        } catch (Exception $e) {
            Log::error("Get trunk status error: " . $e->getMessage());
            return [
                'trunk' => $trunk,
                'reachable' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Close AMI connection
     */
    public function disconnect()
    {
        if ($this->amiSocket) {
            $this->sendAction(['Action' => 'Logoff']);
            fclose($this->amiSocket);
            $this->amiSocket = null;
        }
    }

    /**
     * Destructor to clean up
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
