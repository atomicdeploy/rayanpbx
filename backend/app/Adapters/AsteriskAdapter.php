<?php

namespace App\Adapters;

use App\Helpers\AsteriskConfig;
use App\Helpers\AsteriskSection;
use App\Helpers\AsteriskConfigHelper;
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
     * Generate PJSIP endpoint sections
     * Returns an array of AsteriskSection objects
     */
    public function generatePjsipEndpointSections($extension): array
    {
        $codecs = $extension->codecs ?? ['ulaw', 'alaw', 'g722'];
        
        return AsteriskConfigHelper::createPjsipEndpointSections(
            $extension->extension_number,
            $extension->secret,
            $extension->context,
            $extension->transport,
            $codecs,
            $extension->direct_media ?? 'no',
            $extension->caller_id ?? '',
            $extension->max_contacts ?? 1,
            $extension->qualify_frequency ?? 60,
            !empty($extension->voicemail_enabled)
        );
    }

    /**
     * Generate PJSIP endpoint configuration as string (for backward compatibility)
     */
    public function generatePjsipEndpoint($extension)
    {
        $sections = $this->generatePjsipEndpointSections($extension);
        $output = '';
        foreach ($sections as $i => $section) {
            $output .= $section->toString();
            if ($i < count($sections) - 1) {
                $output .= "\n";
            }
        }
        return $output;
    }

    /**
     * Ensure PJSIP transport configuration exists
     * Includes both UDP and TCP transports with proper configuration
     */
    public function ensureTransportConfig()
    {
        try {
            // Parse existing config or create new one
            $config = AsteriskConfig::parseFile($this->pjsipConfig);
            
            if ($config === null) {
                // Create new config with transports
                $config = new AsteriskConfig($this->pjsipConfig);
                $config->headerLines = [
                    '; RayanPBX PJSIP Configuration',
                    '; Generated by RayanPBX',
                    '',
                ];
            }

            // Check if both transports exist
            $hasUdpTransport = $config->hasSectionWithType('transport-udp', 'transport');
            $hasTcpTransport = $config->hasSectionWithType('transport-tcp', 'transport');

            if ($hasUdpTransport && $hasTcpTransport) {
                return true; // Transports already configured
            }

            // Remove old transport sections
            $config->removeSectionsByName('transport-udp');
            $config->removeSectionsByName('transport-tcp');

            // Create new transport sections and prepend them
            $transportSections = AsteriskConfigHelper::createTransportSections();
            $newSections = array_merge($transportSections, $config->sections);
            $config->sections = $newSections;

            $result = $config->save();
            
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
     * Generate PJSIP trunk sections
     * Returns an array of AsteriskSection objects
     */
    public function generatePjsipTrunkSections($trunk): array
    {
        $sections = [];

        // Endpoint section
        $endpoint = new AsteriskSection($trunk->name, 'endpoint');
        $endpoint->setProperty('type', 'endpoint');
        $endpoint->setProperty('context', $trunk->context);
        $endpoint->setProperty('disallow', 'all');
        
        $codecs = $trunk->codecs ?? ['ulaw', 'alaw', 'g722'];
        foreach ($codecs as $codec) {
            $endpoint->setProperty('allow', $codec);
        }
        
        $endpoint->setProperty('transport', $trunk->transport);
        $endpoint->setProperty('aors', $trunk->name);
        $endpoint->setProperty('direct_media', $trunk->direct_media ?? 'no');
        
        if (!empty($trunk->from_domain)) {
            $endpoint->setProperty('from_domain', $trunk->from_domain);
        }
        if (!empty($trunk->from_user)) {
            $endpoint->setProperty('from_user', $trunk->from_user);
        }
        if (!empty($trunk->language)) {
            $endpoint->setProperty('language', $trunk->language);
        }
        if (!empty($trunk->username)) {
            $endpoint->setProperty('outbound_auth', $trunk->name);
        }
        
        $sections[] = $endpoint;

        // Auth section (only if username is provided)
        if (!empty($trunk->username)) {
            $auth = new AsteriskSection($trunk->name, 'auth');
            $auth->setProperty('type', 'auth');
            $auth->setProperty('auth_type', 'userpass');
            $auth->setProperty('username', $trunk->username);
            $auth->setProperty('password', $trunk->secret);
            $sections[] = $auth;
        }

        // AOR section
        $aor = new AsteriskSection($trunk->name, 'aor');
        $aor->setProperty('type', 'aor');
        $aor->setProperty('contact', "sip:{$trunk->host}:{$trunk->port}");
        $aor->setProperty('qualify_frequency', (string)($trunk->qualify_frequency ?? 60));
        $sections[] = $aor;

        // Identify section
        $identify = new AsteriskSection($trunk->name, 'identify');
        $identify->setProperty('type', 'identify');
        $identify->setProperty('endpoint', $trunk->name);
        $identify->setProperty('match', $trunk->host);
        $sections[] = $identify;

        return $sections;
    }

    /**
     * Generate PJSIP trunk configuration as string (for backward compatibility)
     */
    public function generatePjsipTrunk($trunk)
    {
        $sections = $this->generatePjsipTrunkSections($trunk);
        $output = '';
        foreach ($sections as $i => $section) {
            $output .= $section->toString();
            if ($i < count($sections) - 1) {
                $output .= "\n";
            }
        }
        return $output;
    }

    /**
     * Write PJSIP configuration sections to file
     */
    public function writePjsipConfigSections(array $sections, string $identifier): bool
    {
        try {
            // Parse existing config or create new one
            $config = AsteriskConfig::parseFile($this->pjsipConfig);
            
            if ($config === null) {
                $config = new AsteriskConfig($this->pjsipConfig);
                $config->headerLines = [
                    '; RayanPBX PJSIP Configuration',
                    '; Generated by RayanPBX',
                    '',
                ];
            }

            // Extract section name from identifier
            $sectionName = $identifier;
            if (str_starts_with($identifier, 'Extension ')) {
                $sectionName = substr($identifier, 10);
            } elseif (str_starts_with($identifier, 'Trunk ')) {
                $sectionName = substr($identifier, 6);
            }

            // Remove existing sections with this name
            $config->removeSectionsByName($sectionName);

            // Add new sections
            foreach ($sections as $section) {
                $config->addSection($section);
            }

            $result = $config->save();
            
            if ($result) {
                $this->gitService->commitChange('pjsip-update', "Updated PJSIP config: {$identifier}");
            }
            
            return $result;
        } catch (Exception $e) {
            report($e);
            return false;
        }
    }

    /**
     * Write configuration to file (backward compatible string-based method)
     */
    public function writePjsipConfig($content, $identifier)
    {
        try {
            // Parse the content as sections
            $newConfig = AsteriskConfig::parseContent($content);
            
            return $this->writePjsipConfigSections($newConfig->sections, $identifier);
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
            $config = AsteriskConfig::parseFile($this->pjsipConfig);
            
            if ($config === null) {
                return true; // Nothing to remove
            }

            // Extract section name from identifier
            $sectionName = $identifier;
            if (str_starts_with($identifier, 'Extension ')) {
                $sectionName = substr($identifier, 10);
            } elseif (str_starts_with($identifier, 'Trunk ')) {
                $sectionName = substr($identifier, 6);
            }

            // Remove all sections with this name
            $config->removeSectionsByName($sectionName);

            $result = $config->save();
            
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
        $config = "\n[from-internal]\n";

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
        $config .= " same => n,Hangup()\n";

        return $config;
    }

    /**
     * Write dialplan configuration to extensions.conf
     */
    public function writeDialplanConfig($content, $identifier)
    {
        try {
            // Parse existing config or create new one
            $config = AsteriskConfig::parseFile($this->extensionsConfig);
            
            if ($config === null) {
                $config = new AsteriskConfig($this->extensionsConfig);
                $config->headerLines = [
                    '; RayanPBX Dialplan Configuration',
                    '; Generated by RayanPBX',
                    '',
                ];
            }

            // For dialplan, replace the internal context
            $config->removeSectionsByName('from-internal');

            // Parse the new content and add it
            $newContent = AsteriskConfig::parseContent($content);
            foreach ($newContent->sections as $section) {
                $config->addSection($section);
            }

            $result = $config->save();
            
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
        $config = "\n[from-internal]\n";

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

        return $config;
    }

    /**
     * Generate incoming call routing for trunks
     * Creates contexts for receiving calls from providers
     */
    public function generateIncomingRouting($trunk, $didMappings = [])
    {
        $config = "\n[{$trunk->context}]\n";

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
