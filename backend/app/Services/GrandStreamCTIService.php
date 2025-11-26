<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * GrandStream CTI/CSTA Service
 * 
 * Provides Computer-Telephony Integration (CTI) and CSTA operations
 * for GrandStream GXP series phones.
 * 
 * Based on:
 * - GrandStream CTI Guide
 * - GXP16xx Administration Guide
 */
class GrandStreamCTIService
{
    protected HttpClientService $httpClient;

    // CTI operation commands
    public const CMD_ACCEPT_CALL = 'acceptcall';
    public const CMD_REJECT_CALL = 'rejectcall';
    public const CMD_END_CALL = 'endcall';
    public const CMD_HOLD = 'hold';
    public const CMD_UNHOLD = 'unhold';
    public const CMD_TRANSFER = 'transfer';
    public const CMD_ATTENDED_TRANSFER = 'attended_transfer';
    public const CMD_BLIND_TRANSFER = 'blind_transfer';
    public const CMD_CONFERENCE = 'conference';
    public const CMD_MUTE = 'mute';
    public const CMD_UNMUTE = 'unmute';
    public const CMD_DIAL = 'dial';
    public const CMD_REDIAL = 'redial';
    public const CMD_DTMF = 'dtmf';
    public const CMD_INTERCOM = 'intercom';
    public const CMD_PAGING = 'paging';
    public const CMD_DND = 'dnd';
    public const CMD_FORWARD = 'forward';
    public const CMD_PARK = 'park';
    public const CMD_PICKUP = 'pickup';
    public const CMD_SCREENSHOT = 'screenshot';
    public const CMD_LCD_MESSAGE = 'lcd_message';
    public const CMD_REBOOT = 'reboot';
    public const CMD_PROVISION = 'provision';
    public const CMD_UPGRADE = 'upgrade';
    public const CMD_RECORD_START = 'record_start';
    public const CMD_RECORD_STOP = 'record_stop';

    // SNMP P-value parameters for GrandStream phones
    public const P_SNMP_ENABLE = 'P1610';
    public const P_SNMP_COMMUNITY = 'P1611';
    public const P_SNMP_TRAP_SERVER = 'P1612';
    public const P_SNMP_TRAP_PORT = 'P1613';
    public const P_SNMP_VERSION = 'P1614';
    public const P_SNMP_USERNAME = 'P1615';
    public const P_SNMP_SECURITY_LEVEL = 'P1616';

    // CTI P-value parameters
    public const P_CTI_ENABLE = 'P1650';
    public const P_CTI_NO_AUTH = 'P1651';

    public function __construct(?HttpClientService $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClientService();
    }

    /**
     * Get phone status including call states
     */
    public function getPhoneStatus(string $ip, array $credentials = []): array
    {
        return $this->executeAPIRequest($ip, '/cgi-bin/api-get_phone_status', [], $credentials);
    }

    /**
     * Get line/account status
     */
    public function getLineStatus(string $ip, int $lineId, array $credentials = []): array
    {
        return $this->executeAPIRequest($ip, '/cgi-bin/api-get_line_status', [
            'line' => $lineId,
        ], $credentials);
    }

    /**
     * Get account registration status
     */
    public function getAccountStatus(string $ip, int $accountId, array $credentials = []): array
    {
        return $this->executeAPIRequest($ip, '/cgi-bin/api-get_account_status', [
            'account' => $accountId,
        ], $credentials);
    }

    /**
     * Accept incoming call
     */
    public function acceptCall(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_ACCEPT_CALL, $lineId, [], $credentials);
    }

    /**
     * Reject incoming call
     */
    public function rejectCall(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_REJECT_CALL, $lineId, [], $credentials);
    }

    /**
     * End/hang up current call
     */
    public function endCall(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_END_CALL, $lineId, [], $credentials);
    }

    /**
     * Place call on hold
     */
    public function holdCall(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_HOLD, $lineId, [], $credentials);
    }

    /**
     * Resume held call
     */
    public function unholdCall(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_UNHOLD, $lineId, [], $credentials);
    }

    /**
     * Dial a number
     */
    public function dial(string $ip, string $number, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_DIAL, $lineId, [
            'number' => $number,
        ], $credentials);
    }

    /**
     * Redial last number
     */
    public function redial(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_REDIAL, $lineId, [], $credentials);
    }

    /**
     * Send DTMF tones
     */
    public function sendDTMF(string $ip, string $digits, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_DTMF, $lineId, [
            'value' => $digits,
        ], $credentials);
    }

    /**
     * Perform blind transfer
     */
    public function blindTransfer(string $ip, string $target, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_BLIND_TRANSFER, $lineId, [
            'target' => $target,
        ], $credentials);
    }

    /**
     * Initiate attended transfer
     */
    public function attendedTransfer(string $ip, string $target, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_ATTENDED_TRANSFER, $lineId, [
            'target' => $target,
        ], $credentials);
    }

    /**
     * Start conference call
     */
    public function conference(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_CONFERENCE, $lineId, [], $credentials);
    }

    /**
     * Mute call
     */
    public function mute(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_MUTE, $lineId, [], $credentials);
    }

    /**
     * Unmute call
     */
    public function unmute(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_UNMUTE, $lineId, [], $credentials);
    }

    /**
     * Enable/disable Do Not Disturb
     */
    public function setDND(string $ip, bool $enable, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_DND, null, [
            'value' => $enable ? '1' : '0',
        ], $credentials);
    }

    /**
     * Set call forwarding
     */
    public function setForward(
        string $ip,
        bool $enable,
        ?string $target = null,
        ?string $type = null,
        array $credentials = []
    ): array {
        $params = ['value' => $enable ? '1' : '0'];
        
        if ($target !== null) {
            $params['target'] = $target;
        }
        
        if ($type !== null) {
            $params['type'] = $type; // unconditional, busy, noanswer
        }
        
        return $this->executePhoneOperation($ip, self::CMD_FORWARD, null, $params, $credentials);
    }

    /**
     * Initiate intercom call
     */
    public function intercom(string $ip, string $number, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_INTERCOM, $lineId, [
            'number' => $number,
        ], $credentials);
    }

    /**
     * Initiate paging call
     */
    public function paging(string $ip, string $number, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_PAGING, $lineId, [
            'number' => $number,
        ], $credentials);
    }

    /**
     * Park call
     */
    public function parkCall(string $ip, ?string $slot = null, ?int $lineId = null, array $credentials = []): array
    {
        $params = [];
        if ($slot !== null) {
            $params['slot'] = $slot;
        }
        
        return $this->executePhoneOperation($ip, self::CMD_PARK, $lineId, $params, $credentials);
    }

    /**
     * Pickup ringing call
     */
    public function pickupCall(string $ip, ?string $extension = null, array $credentials = []): array
    {
        $params = [];
        if ($extension !== null) {
            $params['extension'] = $extension;
        }
        
        return $this->executePhoneOperation($ip, self::CMD_PICKUP, null, $params, $credentials);
    }

    /**
     * Display message on phone LCD
     */
    public function displayLCDMessage(string $ip, string $message, ?int $duration = null, array $credentials = []): array
    {
        $params = ['message' => $message];
        if ($duration !== null) {
            $params['duration'] = $duration;
        }
        
        return $this->executePhoneOperation($ip, self::CMD_LCD_MESSAGE, null, $params, $credentials);
    }

    /**
     * Take screenshot of phone display
     */
    public function takeScreenshot(string $ip, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_SCREENSHOT, null, [], $credentials);
    }

    /**
     * Trigger phone to re-provision
     */
    public function triggerProvision(string $ip, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_PROVISION, null, [], $credentials);
    }

    /**
     * Trigger firmware upgrade
     */
    public function triggerUpgrade(string $ip, ?string $firmwareUrl = null, array $credentials = []): array
    {
        $params = [];
        if ($firmwareUrl !== null) {
            $params['url'] = $firmwareUrl;
        }
        
        return $this->executePhoneOperation($ip, self::CMD_UPGRADE, null, $params, $credentials);
    }

    /**
     * Start call recording
     */
    public function startRecording(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_RECORD_START, $lineId, [], $credentials);
    }

    /**
     * Stop call recording
     */
    public function stopRecording(string $ip, ?int $lineId = null, array $credentials = []): array
    {
        return $this->executePhoneOperation($ip, self::CMD_RECORD_STOP, $lineId, [], $credentials);
    }

    /**
     * Enable CTI functionality on the phone
     */
    public function enableCTI(string $ip, array $credentials = []): array
    {
        $config = [
            self::P_CTI_ENABLE => '1',
            self::P_CTI_NO_AUTH => '1',
        ];
        
        return $this->setPhoneConfig($ip, $config, $credentials);
    }

    /**
     * Disable CTI functionality
     */
    public function disableCTI(string $ip, array $credentials = []): array
    {
        $config = [
            self::P_CTI_ENABLE => '0',
        ];
        
        return $this->setPhoneConfig($ip, $config, $credentials);
    }

    /**
     * Enable SNMP monitoring
     */
    public function enableSNMP(string $ip, array $snmpConfig, array $credentials = []): array
    {
        $config = [
            self::P_SNMP_ENABLE => '1',
        ];
        
        if (isset($snmpConfig['community'])) {
            $config[self::P_SNMP_COMMUNITY] = $snmpConfig['community'];
        }
        
        if (isset($snmpConfig['trap_server'])) {
            $config[self::P_SNMP_TRAP_SERVER] = $snmpConfig['trap_server'];
        }
        
        if (isset($snmpConfig['trap_port'])) {
            $config[self::P_SNMP_TRAP_PORT] = (string) $snmpConfig['trap_port'];
        }
        
        // SNMP version: 0=v1, 1=v2c, 2=v3
        if (isset($snmpConfig['version'])) {
            $versionMap = ['v1' => '0', 'v2c' => '1', 'v3' => '2'];
            $config[self::P_SNMP_VERSION] = $versionMap[$snmpConfig['version']] ?? '1';
        }
        
        return $this->setPhoneConfig($ip, $config, $credentials);
    }

    /**
     * Disable SNMP monitoring
     */
    public function disableSNMP(string $ip, array $credentials = []): array
    {
        $config = [
            self::P_SNMP_ENABLE => '0',
        ];
        
        return $this->setPhoneConfig($ip, $config, $credentials);
    }

    /**
     * Get SNMP configuration status
     */
    public function getSNMPStatus(string $ip, array $credentials = []): array
    {
        $result = $this->getPhoneConfig($ip, $credentials);
        
        if (! $result['success']) {
            return $result;
        }
        
        $config = $result['config'] ?? [];
        
        $versionMap = ['0' => 'v1', '1' => 'v2c', '2' => 'v3'];
        
        return [
            'success' => true,
            'snmp' => [
                'enabled' => ($config[self::P_SNMP_ENABLE] ?? '0') === '1',
                'community' => $config[self::P_SNMP_COMMUNITY] ?? '',
                'trap_server' => $config[self::P_SNMP_TRAP_SERVER] ?? '',
                'trap_port' => (int) ($config[self::P_SNMP_TRAP_PORT] ?? 162),
                'version' => $versionMap[$config[self::P_SNMP_VERSION] ?? '1'] ?? 'v2c',
            ],
        ];
    }

    /**
     * Get phone configuration
     */
    public function getPhoneConfig(string $ip, array $credentials = []): array
    {
        return $this->executeAPIRequest($ip, '/cgi-bin/api-get_config', [], $credentials);
    }

    /**
     * Set phone configuration
     */
    public function setPhoneConfig(string $ip, array $config, array $credentials = []): array
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
                return [
                    'success' => false,
                    'error' => 'HTTP error: ' . $response->status(),
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Configuration updated successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to set phone config', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Provision CTI and SNMP features on phone
     */
    public function provisionCTIFeatures(string $ip, array $options, array $credentials = []): array
    {
        $results = [];
        
        // Enable CTI if requested
        if ($options['enable_cti'] ?? true) {
            $result = $this->enableCTI($ip, $credentials);
            $results['cti'] = $result;
            
            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to enable CTI: ' . ($result['error'] ?? 'Unknown error'),
                    'results' => $results,
                ];
            }
        }
        
        // Enable SNMP if requested
        if ($options['enable_snmp'] ?? false) {
            $snmpConfig = $options['snmp_config'] ?? [
                'community' => 'public',
                'version' => 'v2c',
            ];
            
            $result = $this->enableSNMP($ip, $snmpConfig, $credentials);
            $results['snmp'] = $result;
            
            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to enable SNMP: ' . ($result['error'] ?? 'Unknown error'),
                    'results' => $results,
                ];
            }
        }
        
        return [
            'success' => true,
            'message' => 'CTI features provisioned successfully',
            'results' => $results,
        ];
    }

    /**
     * Test CTI and SNMP functionality
     */
    public function testCTIFeatures(string $ip, array $credentials = []): array
    {
        $results = [
            'cti' => false,
            'snmp' => false,
        ];
        
        // Test CTI by getting phone status
        $statusResult = $this->getPhoneStatus($ip, $credentials);
        $results['cti'] = $statusResult['success'] ?? false;
        
        // Test SNMP by checking if enabled
        $snmpResult = $this->getSNMPStatus($ip, $credentials);
        $results['snmp'] = ($snmpResult['snmp']['enabled'] ?? false);
        
        return [
            'success' => true,
            'results' => $results,
            'cti_working' => $results['cti'],
            'snmp_enabled' => $results['snmp'],
        ];
    }

    /**
     * Execute a phone operation command
     */
    protected function executePhoneOperation(
        string $ip,
        string $command,
        ?int $lineId = null,
        array $extraParams = [],
        array $credentials = []
    ): array {
        $params = array_merge(['cmd' => $command], $extraParams);
        
        if ($lineId !== null) {
            $params['line'] = $lineId;
        }
        
        return $this->executeAPIRequest($ip, '/cgi-bin/api-phone_operation', $params, $credentials);
    }

    /**
     * Execute an API request to the phone
     */
    protected function executeAPIRequest(
        string $ip,
        string $endpoint,
        array $params = [],
        array $credentials = []
    ): array {
        $username = $credentials['username'] ?? 'admin';
        $password = $credentials['password'] ?? '';
        
        try {
            $url = "http://{$ip}{$endpoint}";
            if (! empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            
            $response = $this->httpClient->withBasicAuth(
                $url,
                $username,
                $password,
                'GET',
                [],
                ['timeout' => 5]
            );
            
            $body = $response->body();
            $data = [];
            
            // Try JSON first
            if (str_contains($response->header('Content-Type') ?? '', 'application/json')) {
                $data = $response->json() ?? [];
            } else {
                // Try parsing as key=value pairs
                $data = $this->parseKeyValueResponse($body);
            }
            
            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'data' => $data,
                'body' => $body,
            ];
        } catch (\Exception $e) {
            Log::error('CTI API request failed', [
                'ip' => $ip,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse key=value response format
     */
    protected function parseKeyValueResponse(string $body): array
    {
        $result = [];
        $lines = explode("\n", $body);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $result[trim($parts[0])] = trim($parts[1]);
            }
        }
        
        return $result;
    }
}
