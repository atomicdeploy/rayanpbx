<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * PJSIP Interaction Service
 * Direct interaction with Asterisk PJSIP subsystem via AMI
 * Provides real-time endpoint status, registration validation, and hooks
 */
class PjsipService
{
    private $amiHost;
    private $amiPort;
    private $amiUsername;
    private $amiSecret;
    
    public function __construct()
    {
        $this->amiHost = config('rayanpbx.asterisk.ami_host', '127.0.0.1');
        $this->amiPort = config('rayanpbx.asterisk.ami_port', 5038);
        $this->amiUsername = config('rayanpbx.asterisk.ami_username', 'admin');
        $this->amiSecret = config('rayanpbx.asterisk.ami_secret', '');
    }
    
    /**
     * Connect to AMI and return socket
     */
    private function connectAMI()
    {
        try {
            $socket = fsockopen($this->amiHost, $this->amiPort, $errno, $errstr, 5);
            if (!$socket) {
                throw new Exception("Cannot connect to AMI: $errstr ($errno)");
            }
            
            // Read welcome banner
            $this->readResponse($socket);
            
            // Login
            $this->sendCommand($socket, [
                'Action' => 'Login',
                'Username' => $this->amiUsername,
                'Secret' => $this->amiSecret
            ]);
            
            $response = $this->readResponse($socket);
            if (!str_contains($response, 'Success')) {
                throw new Exception("AMI login failed");
            }
            
            return $socket;
        } catch (Exception $e) {
            Log::error("AMI connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Send command to AMI
     */
    private function sendCommand($socket, array $command)
    {
        $message = '';
        foreach ($command as $key => $value) {
            $message .= "$key: $value\r\n";
        }
        $message .= "\r\n";
        fwrite($socket, $message);
    }
    
    /**
     * Read response from AMI
     */
    private function readResponse($socket, $timeout = 5)
    {
        $response = '';
        $start = time();
        
        while (!feof($socket) && (time() - $start) < $timeout) {
            $line = fgets($socket);
            if ($line === false) break;
            
            $response .= $line;
            
            // Check for end of response
            if (trim($line) == '' || str_contains($line, '--END COMMAND--')) {
                break;
            }
        }
        
        return $response;
    }
    
    /**
     * Execute PJSIP CLI command
     */
    private function executePjsipCommand($command)
    {
        $socket = $this->connectAMI();
        if (!$socket) {
            return ['success' => false, 'error' => 'Cannot connect to AMI'];
        }
        
        try {
            $this->sendCommand($socket, [
                'Action' => 'Command',
                'Command' => $command
            ]);
            
            $response = $this->readResponse($socket, 10);
            fclose($socket);
            
            return ['success' => true, 'output' => $response];
        } catch (Exception $e) {
            fclose($socket);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Validate trunk connection
     * Tests if trunk is reachable and properly configured
     */
    public function validateTrunkConnection($trunkName)
    {
        $result = [
            'trunk' => $trunkName,
            'reachable' => false,
            'registered' => false,
            'qualify_status' => 'unknown',
            'latency_ms' => null,
            'errors' => []
        ];
        
        // Check endpoint status
        $endpointStatus = $this->executePjsipCommand("pjsip show endpoint $trunkName");
        
        if (!$endpointStatus['success']) {
            $result['errors'][] = "Failed to query endpoint: " . ($endpointStatus['error'] ?? 'Unknown error');
            return $result;
        }
        
        $output = $endpointStatus['output'];
        
        // Parse endpoint status
        if (str_contains($output, 'Endpoint:') && str_contains($output, $trunkName)) {
            $result['reachable'] = true;
            
            // Check qualify status
            if (preg_match('/Status\s*:\s*(\w+)/', $output, $match)) {
                $status = trim($match[1]);
                $result['qualify_status'] = strtolower($status);
                
                if (in_array($status, ['Reachable', 'Qual'])) {
                    $result['registered'] = true;
                }
            }
            
            // Extract latency if available
            if (preg_match('/RTT\s*:\s*([\d\.]+)\s*ms/', $output, $match)) {
                $result['latency_ms'] = floatval($match[1]);
            }
        } else {
            $result['errors'][] = "Endpoint not found in Asterisk - check PJSIP configuration";
        }
        
        // Check AOR registration (for registering trunks)
        $aorStatus = $this->executePjsipCommand("pjsip show aor $trunkName");
        
        if ($aorStatus['success'] && str_contains($aorStatus['output'], 'Contacts:')) {
            if (preg_match('/Contacts:\s*([^\s]+)/i', $aorStatus['output'], $match)) {
                $contacts = trim($match[1]);
                if ($contacts !== '0' && !empty($contacts)) {
                    $result['registered'] = true;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Validate extension registration
     * Checks if extension is properly registered
     */
    public function validateExtensionRegistration($extension)
    {
        $result = [
            'extension' => $extension,
            'registered' => false,
            'contact' => null,
            'user_agent' => null,
            'ip_address' => null,
            'port' => null,
            'expiry' => null,
            'errors' => []
        ];
        
        // Query AOR for contacts
        $aorStatus = $this->executePjsipCommand("pjsip show aor $extension");
        
        if (!$aorStatus['success']) {
            $result['errors'][] = "Failed to query AOR: " . ($aorStatus['error'] ?? 'Unknown error');
            return $result;
        }
        
        $output = $aorStatus['output'];
        
        // Parse contact information
        if (preg_match('/Contact:\s*([^\s]+)\s+([^\s]+)\s+Avail\s+([\d\.]+)/', $output, $match)) {
            $result['registered'] = true;
            $result['contact'] = trim($match[1]);
            
            // Extract IP and port from contact
            if (preg_match('/sip:.*@([\d\.]+):(\d+)/', $result['contact'], $ipMatch)) {
                $result['ip_address'] = $ipMatch[1];
                $result['port'] = intval($ipMatch[2]);
            }
            
            $result['expiry'] = floatval($match[3]);
        }
        
        // Get endpoint details for User-Agent
        $endpointStatus = $this->executePjsipCommand("pjsip show endpoint $extension");
        
        if ($endpointStatus['success']) {
            if (preg_match('/User-Agent:\s*(.+)$/m', $endpointStatus['output'], $match)) {
                $result['user_agent'] = trim($match[1]);
            }
        }
        
        if (!$result['registered']) {
            $result['errors'][] = "Extension is not registered - check SIP client configuration";
        }
        
        return $result;
    }
    
    /**
     * Test call routing
     * Validates that dialplan routing works for a given number
     */
    public function testCallRouting($fromExtension, $toNumber)
    {
        $result = [
            'from' => $fromExtension,
            'to' => $toNumber,
            'route_found' => false,
            'context' => null,
            'application' => null,
            'trunk' => null,
            'errors' => []
        ];
        
        // Use dialplan show to trace routing
        $dialplanCheck = $this->executePjsipCommand("dialplan show $toNumber@from-internal");
        
        if (!$dialplanCheck['success']) {
            $result['errors'][] = "Failed to query dialplan: " . ($dialplanCheck['error'] ?? 'Unknown error');
            return $result;
        }
        
        $output = $dialplanCheck['output'];
        
        // Parse dialplan output
        if (str_contains($output, "Extension '" . $toNumber . "'")) {
            $result['route_found'] = true;
            $result['context'] = 'from-internal';
            
            // Extract application (Dial, Hangup, etc.)
            if (preg_match('/\d+\.\s+(\w+)\(/m', $output, $match)) {
                $result['application'] = $match[1];
            }
            
            // Extract trunk if present
            if (preg_match('/PJSIP\/.*@([^\),]+)/m', $output, $match)) {
                $result['trunk'] = $match[1];
            }
        } else {
            $result['errors'][] = "No routing found for number $toNumber in context from-internal";
        }
        
        return $result;
    }
    
    /**
     * Setup registration hooks
     * Returns AMI event hooks configuration for monitoring
     */
    public function getRegistrationHooks()
    {
        return [
            'events' => [
                'PeerStatus' => [
                    'description' => 'Fired when a SIP peer changes status',
                    'fields' => ['Peer', 'PeerStatus', 'Cause']
                ],
                'Registry' => [
                    'description' => 'Fired when outbound registration status changes',
                    'fields' => ['ChannelType', 'Username', 'Domain', 'Status']
                ],
                'ContactStatus' => [
                    'description' => 'Fired when PJSIP contact status changes',
                    'fields' => ['URI', 'ContactStatus', 'AOR', 'EndpointName']
                ],
                'DeviceStateChange' => [
                    'description' => 'Fired when device state changes',
                    'fields' => ['Device', 'State']
                ]
            ],
            'webhooks' => [
                'extension_registered' => '/api/webhooks/extension-registered',
                'extension_unregistered' => '/api/webhooks/extension-unregistered',
                'trunk_status_change' => '/api/webhooks/trunk-status-change'
            ]
        ];
    }
    
    /**
     * Setup GrandStream provisioning hooks
     * Configuration for automatic phone provisioning
     */
    public function getGrandstreamHooks()
    {
        return [
            'provisioning' => [
                'protocol' => 'http', // or https
                'path' => '/provisioning/grandstream',
                'auth_required' => true
            ],
            'models' => [
                'GXP1625' => [
                    'template' => 'gxp1620.xml',
                    'firmware' => '1.0.11.23',
                    'capabilities' => ['2_lines', 'hd_audio', 'poe']
                ],
                'GXP1628' => [
                    'template' => 'gxp1620.xml',
                    'firmware' => '1.0.11.23',
                    'capabilities' => ['2_lines', 'hd_audio', 'poe', 'color_lcd']
                ],
                'GXP1630' => [
                    'template' => 'gxp1620.xml',
                    'firmware' => '1.0.11.23',
                    'capabilities' => ['3_lines', 'hd_audio', 'poe', 'color_lcd', 'bluetooth']
                ]
            ],
            'events' => [
                'phone_boot' => [
                    'description' => 'Phone requests config on boot',
                    'action' => 'serve_config'
                ],
                'phone_registered' => [
                    'description' => 'Phone successfully registers',
                    'action' => 'update_status'
                ]
            ]
        ];
    }
}
