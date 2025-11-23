<?php

namespace App\Adapters;

use Exception;

class AsteriskAdapter
{
    private $amiHost;
    private $amiPort;
    private $amiUsername;
    private $amiSecret;
    private $configPath;
    private $pjsipConfig;
    private $extensionsConfig;
    
    public function __construct()
    {
        $this->amiHost = config('rayanpbx.asterisk.ami_host', '127.0.0.1');
        $this->amiPort = config('rayanpbx.asterisk.ami_port', 5038);
        $this->amiUsername = config('rayanpbx.asterisk.ami_username', 'admin');
        $this->amiSecret = config('rayanpbx.asterisk.ami_secret', '');
        $this->configPath = config('rayanpbx.asterisk.config_path', '/etc/asterisk');
        $this->pjsipConfig = config('rayanpbx.asterisk.pjsip_config', '/etc/asterisk/pjsip.conf');
        $this->extensionsConfig = config('rayanpbx.asterisk.extensions_config', '/etc/asterisk/extensions.conf');
    }
    
    /**
     * Connect to AMI
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
        while (!feof($socket)) {
            $line = fgets($socket);
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
        $config .= "direct_media=" . ($extension->direct_media ?? 'no') . "\n";
        
        if ($extension->caller_id) {
            $config .= "callerid={$extension->caller_id}\n";
        }
        
        // Add voicemail integration if enabled
        if (!empty($extension->voicemail_enabled)) {
            $config .= "mailboxes={$extension->extension_number}@default\n";
        }
        
        $config .= "\n[{$extension->extension_number}]\n";
        $config .= "type=auth\n";
        $config .= "auth_type=userpass\n";
        $config .= "username={$extension->extension_number}\n";
        $config .= "password={$extension->secret}\n";
        
        $config .= "\n[{$extension->extension_number}]\n";
        $config .= "type=aor\n";
        $config .= "max_contacts={$extension->max_contacts}\n";
        $config .= "remove_existing=yes\n";
        $config .= "qualify_frequency=" . ($extension->qualify_frequency ?? 60) . "\n";
        
        $config .= "; END MANAGED - Extension {$extension->extension_number}\n";
        
        return $config;
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
        $config .= "direct_media=" . ($trunk->direct_media ?? 'no') . "\n";
        
        // Add from_domain if specified (required by many providers)
        if (!empty($trunk->from_domain)) {
            $config .= "from_domain={$trunk->from_domain}\n";
        }
        
        // Add from_user if specified
        if (!empty($trunk->from_user)) {
            $config .= "from_user={$trunk->from_user}\n";
        }
        
        // Add language if specified
        if (!empty($trunk->language)) {
            $config .= "language={$trunk->language}\n";
        }
        
        // Only add auth if username is provided
        if (!empty($trunk->username)) {
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
        $config .= "qualify_frequency=" . ($trunk->qualify_frequency ?? 60) . "\n";
        
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
            $newConfig = $existingConfig . $content;
            
            // Write to file (requires proper permissions)
            return file_put_contents($this->pjsipConfig, $newConfig) !== false;
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
            return file_put_contents($this->pjsipConfig, $newConfig) !== false;
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
            if (!$trunk->enabled) continue;
            
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
        
        if (!empty($didMappings)) {
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
        if (!$socket) {
            return false;
        }
        
        try {
            $this->sendCommand($socket, [
                'Action' => 'Reload',
                'Module' => 'res_pjsip.so'
            ]);
            
            $this->readResponse($socket);
            
            $this->sendCommand($socket, [
                'Action' => 'DialplanReload'
            ]);
            
            $this->readResponse($socket);
            
            fclose($socket);
            return true;
        } catch (Exception $e) {
            report($e);
            fclose($socket);
            return false;
        }
    }
    
    /**
     * Get extension status
     */
    public function getExtensionStatus($extension)
    {
        $socket = $this->connectAMI();
        if (!$socket) {
            return 'unknown';
        }
        
        try {
            $this->sendCommand($socket, [
                'Action' => 'ExtensionState',
                'Exten' => $extension,
                'Context' => 'from-internal'
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
}
