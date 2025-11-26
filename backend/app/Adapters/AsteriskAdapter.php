<?php

namespace App\Adapters;

use App\Services\AsteriskConfigGitService;
use Exception;

class AsteriskAdapter
{
    /**
     * Timeout in seconds for AMI socket read/write operations
     */
    private const AMI_SOCKET_TIMEOUT = 5;

    /**
     * Maximum iterations for readResponse to prevent infinite loops
     */
    private const MAX_READ_ITERATIONS = 1000;

    private $amiHost;

    private $amiPort;

    private $amiUsername;

    private $amiSecret;

    private $configPath;

    private $pjsipConfig;

    private $extensionsConfig;

    private $gitService;

    public function __construct()
    {
        $this->amiHost = config('rayanpbx.asterisk.ami_host', '127.0.0.1');
        $this->amiPort = config('rayanpbx.asterisk.ami_port', 5038);
        $this->amiUsername = config('rayanpbx.asterisk.ami_username', 'admin');
        $this->amiSecret = config('rayanpbx.asterisk.ami_secret', '');
        $this->configPath = config('rayanpbx.asterisk.config_path', '/etc/asterisk');
        $this->pjsipConfig = config('rayanpbx.asterisk.pjsip_config', '/etc/asterisk/pjsip.conf');
        $this->extensionsConfig = config('rayanpbx.asterisk.extensions_config', '/etc/asterisk/extensions.conf');
        $this->gitService = new AsteriskConfigGitService();
    }

    /**
     * Connect to AMI
     */
    private function connectAMI()
    {
        try {
            $socket = @fsockopen($this->amiHost, $this->amiPort, $errno, $errstr, self::AMI_SOCKET_TIMEOUT);
            if (! $socket) {
                throw new Exception("Cannot connect to AMI: $errstr ($errno)");
            }

            // Set stream timeout for read/write operations
            stream_set_timeout($socket, self::AMI_SOCKET_TIMEOUT);

            // Read welcome banner
            $this->readResponse($socket);

            // Login
            $this->sendCommand($socket, [
                'Action' => 'Login',
                'Username' => $this->amiUsername,
                'Secret' => $this->amiSecret,
            ]);

            $response = $this->readResponse($socket);
            if (! str_contains($response, 'Success')) {
                throw new Exception('AMI login failed');
            }

            return $socket;
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Send AMI command
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
     * Read AMI response
     */
    private function readResponse($socket)
    {
        $response = '';
        $iterations = 0;

        while (! feof($socket) && $iterations < self::MAX_READ_ITERATIONS) {
            $iterations++;
            $line = fgets($socket);

            // Check for read failure or timeout (fgets returns false on error/timeout)
            if ($line === false) {
                break;
            }

            $response .= $line;
            if (trim($line) == '') {
                break;
            }
        }

        return $response;
    }

    /**
     * Generate PJSIP endpoint configuration
     */
    public function generatePjsipEndpoint($extension)
    {
        $config = "\n; BEGIN MANAGED - Extension {$extension->extension_number}\n";
        $config .= "[{$extension->extension_number}]\n";
        $config .= "type=endpoint\n";
        $config .= "context={$extension->context}\n";
        $config .= "disallow=all\n";

        $codecs = $extension->codecs ?? ['ulaw', 'alaw', 'g722'];
        foreach ($codecs as $codec) {
            $config .= "allow={$codec}\n";
        }

        $config .= "transport={$extension->transport}\n";
        $config .= "auth={$extension->extension_number}\n";
        $config .= "aors={$extension->extension_number}\n";

        // Add direct_media for extensions (can be enabled for LAN)
        $config .= 'direct_media='.($extension->direct_media ?? 'no')."\n";

        if ($extension->caller_id) {
            $config .= "callerid={$extension->caller_id}\n";
        }

        // Add voicemail integration if enabled
        if (! empty($extension->voicemail_enabled)) {
            $config .= "mailboxes={$extension->extension_number}@default\n";
        }

        // SIP Presence and Device State support
        // subscribe_context enables presence subscriptions for BLF/monitoring
        $config .= "subscribe_context={$extension->context}\n";
        // device_state_busy_at controls when endpoint reports "busy" state
        $config .= "device_state_busy_at=1\n";

        $config .= "\n[{$extension->extension_number}]\n";
        $config .= "type=auth\n";
        $config .= "auth_type=userpass\n";
        $config .= "username={$extension->extension_number}\n";
        $config .= "password={$extension->secret}\n";

        $config .= "\n[{$extension->extension_number}]\n";
        $config .= "type=aor\n";
        $config .= "max_contacts={$extension->max_contacts}\n";
        $config .= "remove_existing=yes\n";
        $config .= 'qualify_frequency='.($extension->qualify_frequency ?? 60)."\n";
        // Support outbound publish for presence
        $config .= "support_outbound=yes\n";

        $config .= "; END MANAGED - Extension {$extension->extension_number}\n";

        return $config;
    }

    /**
     * Ensure PJSIP transport configuration exists
     * Includes both UDP and TCP transports with proper configuration
     */
    public function ensureTransportConfig()
    {
        try {
            $config = @file_get_contents($this->pjsipConfig) ?: '';

            // Check if both transports are configured
            $hasUdpTransport = str_contains($config, '[transport-udp]') && str_contains($config, 'type=transport');
            $hasTcpTransport = str_contains($config, '[transport-tcp]') && str_contains($config, 'type=transport');

            if ($hasUdpTransport && $hasTcpTransport) {
                return true; // Transports already configured
            }

            // Remove old RayanPBX transport section if exists
            $config = preg_replace('/; BEGIN MANAGED - RayanPBX Transport.*?; END MANAGED - RayanPBX Transport\n/s', '', $config);
            $config = preg_replace('/; BEGIN MANAGED - RayanPBX Transports.*?; END MANAGED - RayanPBX Transports\n/s', '', $config);

            // Generate complete transport configuration
            $transportConfig = "; BEGIN MANAGED - RayanPBX Transports\n";
            $transportConfig .= "; Generated by RayanPBX - SIP Transports Configuration\n\n";

            // UDP Transport (primary - most common)
            $transportConfig .= "[transport-udp]\n";
            $transportConfig .= "type=transport\n";
            $transportConfig .= "protocol=udp\n";
            $transportConfig .= "bind=0.0.0.0:5060\n";
            $transportConfig .= "allow_reload=yes\n";
            $transportConfig .= "\n";

            // TCP Transport (for reliability and NAT traversal)
            $transportConfig .= "[transport-tcp]\n";
            $transportConfig .= "type=transport\n";
            $transportConfig .= "protocol=tcp\n";
            $transportConfig .= "bind=0.0.0.0:5060\n";
            $transportConfig .= "allow_reload=yes\n";

            $transportConfig .= "; END MANAGED - RayanPBX Transports\n\n";

            // Prepend transport config
            $config = $transportConfig.$config;

            $result = file_put_contents($this->pjsipConfig, $config) !== false;
            
            if ($result) {
                // Commit changes to Git repository
                $this->gitService->commitChange('transport-update', 'Updated PJSIP transport configuration');
            }
            
            return $result;
        } catch (Exception $e) {
            report($e);

            return false;
        }
    }

    /**
     * Get PJSIP transports from Asterisk
     */
    public function getTransports()
    {
        try {
            $command = 'pjsip show transports';
            $output = shell_exec('asterisk -rx '.escapeshellarg($command).' 2>&1');

            if (empty($output)) {
                return [];
            }

            return $this->parseTransportsList($output);
        } catch (Exception $e) {
            report($e);

            return [];
        }
    }

    /**
     * Parse PJSIP transports list output
     */
    private function parseTransportsList($output)
    {
        $transports = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip header and empty lines
            if (empty($line) || str_contains($line, 'Transport:') && str_contains($line, 'Protocol')) {
                continue;
            }

            // Match transport lines
            if (preg_match('/^\s*(\S+)\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $transports[] = [
                    'name' => $matches[1],
                    'protocol' => $matches[2] ?? 'unknown',
                    'bind' => $matches[3] ?? 'unknown',
                ];
            }
        }

        return $transports;
    }

    /**
     * Generate PJSIP trunk configuration
     */
    public function generatePjsipTrunk($trunk)
    {
        $config = "\n; BEGIN MANAGED - Trunk {$trunk->name}\n";
        $config .= "[{$trunk->name}]\n";
        $config .= "type=endpoint\n";
        $config .= "context={$trunk->context}\n";
        $config .= "disallow=all\n";

        $codecs = $trunk->codecs ?? ['ulaw', 'alaw', 'g722'];
        foreach ($codecs as $codec) {
            $config .= "allow={$codec}\n";
        }

        $config .= "transport={$trunk->transport}\n";
        $config .= "aors={$trunk->name}\n";

        // Add direct_media=no for NAT scenarios (safer default)
        $config .= 'direct_media='.($trunk->direct_media ?? 'no')."\n";

        // Add from_domain if specified (required by many providers)
        if (! empty($trunk->from_domain)) {
            $config .= "from_domain={$trunk->from_domain}\n";
        }

        // Add from_user if specified
        if (! empty($trunk->from_user)) {
            $config .= "from_user={$trunk->from_user}\n";
        }

        // Add language if specified
        if (! empty($trunk->language)) {
            $config .= "language={$trunk->language}\n";
        }

        // Only add auth if username is provided
        if (! empty($trunk->username)) {
            $config .= "outbound_auth={$trunk->name}\n";

            $config .= "\n[{$trunk->name}]\n";
            $config .= "type=auth\n";
            $config .= "auth_type=userpass\n";
            $config .= "username={$trunk->username}\n";
            $config .= "password={$trunk->secret}\n";
        }

        $config .= "\n[{$trunk->name}]\n";
        $config .= "type=aor\n";
        $config .= "contact=sip:{$trunk->host}:{$trunk->port}\n";
        $config .= 'qualify_frequency='.($trunk->qualify_frequency ?? 60)."\n";

        $config .= "\n[{$trunk->name}]\n";
        $config .= "type=identify\n";
        $config .= "endpoint={$trunk->name}\n";
        $config .= "match={$trunk->host}\n";

        $config .= "; END MANAGED - Trunk {$trunk->name}\n";

        return $config;
    }

    /**
     * Write configuration to file
     */
    public function writePjsipConfig($content, $identifier)
    {
        try {
            // Read existing config
            $existingConfig = @file_get_contents($this->pjsipConfig) ?: '';

            // Remove old managed section for this identifier
            $pattern = "/; BEGIN MANAGED - {$identifier}.*?; END MANAGED - {$identifier}\n/s";
            $existingConfig = preg_replace($pattern, '', $existingConfig);

            // Append new config
            $newConfig = $existingConfig.$content;

            // Write to file (requires proper permissions)
            $result = file_put_contents($this->pjsipConfig, $newConfig) !== false;
            
            if ($result) {
                // Commit changes to Git repository
                $this->gitService->commitChange('pjsip-update', "Updated PJSIP config: {$identifier}");
            }
            
            return $result;
        } catch (Exception $e) {
            report($e);

            return false;
        }
    }

    /**
     * Remove configuration from file
     */
    public function removePjsipConfig($identifier)
    {
        try {
            $existingConfig = @file_get_contents($this->pjsipConfig) ?: '';
            $pattern = "/; BEGIN MANAGED - {$identifier}.*?; END MANAGED - {$identifier}\n/s";
            $newConfig = preg_replace($pattern, '', $existingConfig);

            $result = file_put_contents($this->pjsipConfig, $newConfig) !== false;
            
            if ($result) {
                // Commit changes to Git repository
                $this->gitService->commitChange('pjsip-remove', "Removed PJSIP config: {$identifier}");
            }
            
            return $result;
        } catch (Exception $e) {
            report($e);

            return false;
        }
    }

    /**
     * Generate internal dialplan for extensions
     */
    public function generateInternalDialplan($extensions)
    {
        $config = "\n; BEGIN MANAGED - RayanPBX Internal Extensions\n";
        $config .= "[internal]\n";

        // Add hint definitions for presence/BLF support
        // These hints map extension numbers to their PJSIP endpoints for device state monitoring
        $config .= "; Device state hints for presence/BLF support\n";
        foreach ($extensions as $extension) {
            if (! $extension->enabled) {
                continue;
            }
            $extNum = $extension->extension_number;
            $config .= "exten => {$extNum},hint,PJSIP/{$extNum}\n";
        }
        $config .= "\n";

        // Add individual extension rules
        foreach ($extensions as $extension) {
            if (! $extension->enabled) {
                continue;
            }

            $extNum = $extension->extension_number;
            $config .= "exten => {$extNum},1,NoOp(Call to extension {$extNum})\n";
            $config .= " same => n,Dial(PJSIP/{$extNum},30)\n";

            // Add voicemail if enabled
            if ($extension->voicemail_enabled) {
                $config .= " same => n,VoiceMail({$extNum}@default,u)\n";
            }

            $config .= " same => n,Hangup()\n\n";
        }

        // Add pattern matching for extension-to-extension calls
        $config .= "; Pattern match for all extensions\n";
        $config .= "exten => _1XXX,1,NoOp(Extension to extension call: \${EXTEN})\n";
        $config .= " same => n,Dial(PJSIP/\${EXTEN},30)\n";
        $config .= " same => n,Hangup()\n\n";

        $config .= "; END MANAGED - RayanPBX Internal Extensions\n";

        return $config;
    }

    /**
     * Write dialplan configuration to extensions.conf
     */
    public function writeDialplanConfig($content, $identifier)
    {
        try {
            // Read existing config
            $existingConfig = @file_get_contents($this->extensionsConfig) ?: '';

            // Remove old managed section for this identifier
            $pattern = "/; BEGIN MANAGED - {$identifier}.*?; END MANAGED - {$identifier}\n/s";
            $existingConfig = preg_replace($pattern, '', $existingConfig);

            // Append new config
            $newConfig = $existingConfig.$content;

            // Write to file (requires proper permissions)
            $result = file_put_contents($this->extensionsConfig, $newConfig) !== false;
            
            if ($result) {
                // Commit changes to Git repository
                $this->gitService->commitChange('dialplan-update', "Updated dialplan: {$identifier}");
            }
            
            return $result;
        } catch (Exception $e) {
            report($e);

            return false;
        }
    }

    /**
     * Generate dialplan for trunk routing
     */
    public function generateDialplan($trunks)
    {
        $config = "\n; BEGIN MANAGED - RayanPBX Outbound Routing\n";
        $config .= "[from-internal]\n";

        foreach ($trunks as $trunk) {
            if (! $trunk->enabled) {
                continue;
            }

            $prefix = $trunk->prefix;
            $strip = $trunk->strip_digits;

            $config .= "exten => _{$prefix}X.,1,NoOp(Outbound call via {$trunk->name})\n";
            $config .= " same => n,Set(CALLERID(num)=\${CALLERID(num)})\n";

            if ($strip > 0) {
                $config .= " same => n,Set(OUTNUM=\${EXTEN:{$strip}})\n";
            } else {
                $config .= " same => n,Set(OUTNUM=\${EXTEN})\n";
            }

            $config .= " same => n,Dial(PJSIP/\${OUTNUM}@{$trunk->name},60)\n";
            $config .= " same => n,Hangup()\n\n";
        }

        $config .= "; END MANAGED - RayanPBX Outbound Routing\n";

        return $config;
    }

    /**
     * Generate incoming call routing for trunks
     * Creates contexts for receiving calls from providers
     */
    public function generateIncomingRouting($trunk, $didMappings = [])
    {
        $config = "\n; BEGIN MANAGED - Incoming Routing for {$trunk->name}\n";
        $config .= "[{$trunk->context}]\n";

        if (! empty($didMappings)) {
            // Generate specific DID mappings
            foreach ($didMappings as $did => $extension) {
                $config .= "exten => {$did},1,NoOp(Incoming call from {$trunk->name} DID: {$did})\n";
                $config .= " same => n,Set(CALLERID(name)={$did})\n";
                $config .= " same => n,Dial(PJSIP/{$extension},30)\n";
                $config .= " same => n,VoiceMail({$extension}@default,u)\n";
                $config .= " same => n,Hangup()\n\n";
            }
        } else {
            // Default catch-all routing
            $config .= "exten => _X.,1,NoOp(Incoming call from {$trunk->name})\n";
            $config .= " same => n,Dial(PJSIP/\${EXTEN},30)\n";
            $config .= " same => n,VoiceMail(\${EXTEN}@default,u)\n";
            $config .= " same => n,Hangup()\n";
        }

        $config .= "; END MANAGED - Incoming Routing for {$trunk->name}\n";

        return $config;
    }

    /**
     * Reload Asterisk configuration
     */
    public function reload()
    {
        $socket = $this->connectAMI();
        if (! $socket) {
            return false;
        }

        try {
            // Reload PJSIP
            $this->sendCommand($socket, [
                'Action' => 'PJSIPReload',
            ]);

            $response = $this->readResponse($socket);
            $pjsipSuccess = str_contains($response, 'Success') || str_contains($response, 'Response: Success');

            // Reload dialplan
            $this->sendCommand($socket, [
                'Action' => 'DialplanReload',
            ]);

            $response = $this->readResponse($socket);
            $dialplanSuccess = str_contains($response, 'Success') || str_contains($response, 'Response: Success');

            fclose($socket);

            return $pjsipSuccess && $dialplanSuccess;
        } catch (Exception $e) {
            report($e);
            if (is_resource($socket)) {
                fclose($socket);
            }

            return false;
        }
    }

    /**
     * Reload Asterisk using CLI (alternative method)
     */
    public function reloadCLI()
    {
        try {
            // Reload PJSIP via CLI
            $pjsipOutput = shell_exec("asterisk -rx 'pjsip reload' 2>&1");

            // Reload dialplan via CLI
            $dialplanOutput = shell_exec("asterisk -rx 'dialplan reload' 2>&1");

            return [
                'success' => true,
                'pjsip_output' => $pjsipOutput,
                'dialplan_output' => $dialplanOutput,
            ];
        } catch (Exception $e) {
            report($e);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get extension status
     */
    public function getExtensionStatus($extension)
    {
        $socket = $this->connectAMI();
        if (! $socket) {
            return 'unknown';
        }

        try {
            $this->sendCommand($socket, [
                'Action' => 'ExtensionState',
                'Exten' => $extension,
                'Context' => 'from-internal',
            ]);

            $response = $this->readResponse($socket);
            fclose($socket);

            if (str_contains($response, 'State: 0')) {
                return 'registered';
            }

            return 'offline';
        } catch (Exception $e) {
            report($e);
            fclose($socket);

            return 'unknown';
        }
    }

    /**
     * Get PJSIP endpoint details from Asterisk
     */
    public function getPjsipEndpoint($endpoint)
    {
        try {
            $command = "pjsip show endpoint {$endpoint}";
            $output = shell_exec('asterisk -rx '.escapeshellarg($command).' 2>&1');

            if (empty($output) || str_contains($output, 'Unable to find object') || str_contains($output, 'No objects found')) {
                return null;
            }

            return $this->parsePjsipEndpointDetail($output);
        } catch (Exception $e) {
            report($e);

            return null;
        }
    }

    /**
     * Get all PJSIP endpoints from Asterisk
     */
    public function getAllPjsipEndpoints()
    {
        try {
            $command = 'pjsip show endpoints';
            $output = shell_exec('asterisk -rx '.escapeshellarg($command).' 2>&1');

            if (empty($output) || str_contains($output, 'No objects found')) {
                return [];
            }

            return $this->parsePjsipEndpointsList($output);
        } catch (Exception $e) {
            report($e);

            return [];
        }
    }

    /**
     * Parse PJSIP endpoint list output
     */
    private function parsePjsipEndpointsList($output)
    {
        $endpoints = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip header and empty lines
            if (empty($line) || str_contains($line, 'Endpoint:') && str_contains($line, 'State')) {
                continue;
            }

            // Match endpoint lines: "Endpoint:  <name>  <state>  <aors>  <contacts>"
            if (preg_match('/^\s*(\S+)\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $endpoints[] = [
                    'name' => $matches[1],
                    'state' => $matches[2],
                    'contacts' => isset($matches[3]) && $matches[3] !== 'n/a' ? $matches[3] : '0',
                ];
            }
        }

        return $endpoints;
    }

    /**
     * Parse detailed PJSIP endpoint output
     */
    private function parsePjsipEndpointDetail($output)
    {
        $details = [
            'endpoint' => null,
            'state' => 'Unavailable',
            'contacts' => [],
            'transport' => null,
            'auth' => null,
        ];

        $lines = explode("\n", $output);
        $inContactSection = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Parse endpoint name
            if (preg_match('/Endpoint:\s+<Endpoint\/(\S+)>/', $line, $matches)) {
                $details['endpoint'] = $matches[1];
            }

            // Parse DeviceState
            if (preg_match('/DeviceState\s*:\s*(\S+)/', $line, $matches)) {
                $details['state'] = $matches[1];
            }

            // Parse Transport
            if (preg_match('/transport\s*:\s*(\S+)/', $line, $matches)) {
                $details['transport'] = $matches[1];
            }

            // Parse auth
            if (preg_match('/auth\s*:\s*(\S+)/', $line, $matches)) {
                $details['auth'] = $matches[1];
            }

            // Parse contacts section
            if (str_contains($line, 'Contact:')) {
                $inContactSection = true;
                if (preg_match('/Contact:\s+([^\/]+)\/(\S+)/', $line, $matches)) {
                    $details['contacts'][] = [
                        'uri' => $matches[2],
                        'status' => str_contains($line, 'Avail') ? 'Available' : 'Unavailable',
                    ];
                }
            } elseif ($inContactSection && preg_match('/(\S+@[\d.]+:\d+)/', $line, $matches)) {
                $lastContact = end($details['contacts']);
                if ($lastContact && empty($lastContact['uri'])) {
                    $details['contacts'][count($details['contacts']) - 1]['uri'] = $matches[1];
                }
            }
        }

        return $details;
    }

    /**
     * Verify if endpoint exists in Asterisk
     */
    public function verifyEndpointExists($endpoint)
    {
        $details = $this->getPjsipEndpoint($endpoint);

        return $details !== null && isset($details['endpoint']);
    }

    /**
     * Get endpoint registration status
     */
    public function getEndpointRegistrationStatus($endpoint)
    {
        $details = $this->getPjsipEndpoint($endpoint);

        if (! $details) {
            return [
                'registered' => false,
                'status' => 'Not Found',
                'contacts' => 0,
            ];
        }

        $hasActiveContacts = ! empty($details['contacts']) &&
                            count(array_filter($details['contacts'], function ($c) {
                                return isset($c['status']) && $c['status'] === 'Available';
                            })) > 0;

        return [
            'registered' => $hasActiveContacts,
            'status' => $details['state'],
            'contacts' => count($details['contacts']),
            'details' => $details,
        ];
    }
}
