<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * TR-069 (CWMP) Service
 * 
 * Implements TR-069/CWMP protocol for automatic configuration of CPE devices
 * Supports GrandStream and other TR-069 compatible phones
 */
class TR069Service
{
    private string $acsUrl;
    private string $acsUsername;
    private string $acsPassword;
    
    public function __construct()
    {
        $this->acsUrl = config('rayanpbx.tr069.acs_url', 'http://localhost:7547/');
        $this->acsUsername = config('rayanpbx.tr069.acs_username', 'admin');
        $this->acsPassword = config('rayanpbx.tr069.acs_password', bin2hex(random_bytes(16)));
    }
    
    /**
     * Handle Inform message from CPE
     */
    public function handleInform(array $xmlData): array
    {
        $deviceId = $xmlData['DeviceId'] ?? null;
        $serialNumber = $xmlData['DeviceId']['SerialNumber'] ?? null;
        $manufacturer = $xmlData['DeviceId']['Manufacturer'] ?? 'Unknown';
        $model = $xmlData['DeviceId']['ProductClass'] ?? 'Unknown';
        
        if (!$deviceId || !$serialNumber) {
            throw new \Exception('Invalid Inform message: Missing DeviceId');
        }
        
        // Store device information
        $device = [
            'serial_number' => $serialNumber,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'last_inform' => now()->toDateTimeString(),
            'connection_request_url' => $xmlData['Device']['ManagementServer']['ConnectionRequestURL'] ?? null,
            'connection_request_username' => $xmlData['Device']['ManagementServer']['ConnectionRequestUsername'] ?? null,
            'connection_request_password' => $xmlData['Device']['ManagementServer']['ConnectionRequestPassword'] ?? null,
            'parameter_key' => $xmlData['ParameterKey'] ?? '',
            'events' => $xmlData['Event'] ?? [],
        ];
        
        Cache::put("tr069:device:{$serialNumber}", $device, 3600);
        
        Log::info("TR-069 Inform received", [
            'serial' => $serialNumber,
            'manufacturer' => $manufacturer,
            'model' => $model
        ]);
        
        // Return InformResponse
        return [
            'MaxEnvelopes' => 1,
            'CurrentTime' => now()->toIso8601String(),
        ];
    }
    
    /**
     * Get parameter values from CPE
     */
    public function getParameterValues(string $serialNumber, array $parameters): array
    {
        $device = $this->getDevice($serialNumber);
        if (!$device) {
            throw new \Exception("Device not found: {$serialNumber}");
        }
        
        // Send GetParameterValues RPC
        $rpcRequest = [
            'ID' => 'GetParams_' . time(),
            'Method' => 'GetParameterValues',
            'Parameters' => array_map(function($param) {
                return ['Name' => $param];
            }, $parameters)
        ];
        
        return $this->sendRPC($device, $rpcRequest);
    }
    
    /**
     * Set parameter values on CPE
     */
    public function setParameterValues(string $serialNumber, array $parameters, string $parameterKey = ''): array
    {
        $device = $this->getDevice($serialNumber);
        if (!$device) {
            throw new \Exception("Device not found: {$serialNumber}");
        }
        
        // Send SetParameterValues RPC
        $rpcRequest = [
            'ID' => 'SetParams_' . time(),
            'Method' => 'SetParameterValues',
            'ParameterKey' => $parameterKey,
            'ParameterList' => array_map(function($name, $value) {
                return [
                    'Name' => $name,
                    'Value' => $value,
                ];
            }, array_keys($parameters), $parameters)
        ];
        
        return $this->sendRPC($device, $rpcRequest);
    }
    
    /**
     * Configure SIP account on device
     */
    public function configureSipAccount(string $serialNumber, int $accountNumber, array $config): bool
    {
        $parameters = [
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.Enable" => "1",
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.SIP.ProxyServer" => $config['server'] ?? '',
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.SIP.ProxyServerPort" => $config['port'] ?? '5060',
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.SIP.RegistrarServer" => $config['server'] ?? '',
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.SIP.RegistrarServerPort" => $config['port'] ?? '5060',
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.SIP.AuthUserName" => $config['username'] ?? '',
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.SIP.AuthPassword" => $config['password'] ?? '',
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.Line.1.Enable" => "1",
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.Line.1.DirectoryNumber" => $config['extension'] ?? '',
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.Line.1.SIP.AuthUserName" => $config['username'] ?? '',
            "InternetGatewayDevice.Services.VoiceService.1.VoiceProfile.{$accountNumber}.Line.1.SIP.AuthPassword" => $config['password'] ?? '',
        ];
        
        $result = $this->setParameterValues($serialNumber, $parameters, "SIP_CONFIG_" . time());
        
        return isset($result['Status']) && $result['Status'] === 0;
    }
    
    /**
     * Download firmware to device
     */
    public function downloadFirmware(string $serialNumber, string $firmwareUrl, string $fileType = '1 Firmware Upgrade Image'): array
    {
        $device = $this->getDevice($serialNumber);
        if (!$device) {
            throw new \Exception("Device not found: {$serialNumber}");
        }
        
        // Send Download RPC
        $rpcRequest = [
            'ID' => 'Download_' . time(),
            'Method' => 'Download',
            'CommandKey' => 'FW_UPGRADE_' . time(),
            'FileType' => $fileType,
            'URL' => $firmwareUrl,
            'Username' => '',
            'Password' => '',
            'FileSize' => 0,
            'TargetFileName' => '',
            'DelaySeconds' => 0,
            'SuccessURL' => '',
            'FailureURL' => '',
        ];
        
        return $this->sendRPC($device, $rpcRequest);
    }
    
    /**
     * Reboot device
     */
    public function reboot(string $serialNumber): array
    {
        $device = $this->getDevice($serialNumber);
        if (!$device) {
            throw new \Exception("Device not found: {$serialNumber}");
        }
        
        $rpcRequest = [
            'ID' => 'Reboot_' . time(),
            'Method' => 'Reboot',
            'CommandKey' => 'REBOOT_' . time(),
        ];
        
        return $this->sendRPC($device, $rpcRequest);
    }
    
    /**
     * Factory reset device
     */
    public function factoryReset(string $serialNumber): array
    {
        $device = $this->getDevice($serialNumber);
        if (!$device) {
            throw new \Exception("Device not found: {$serialNumber}");
        }
        
        $rpcRequest = [
            'ID' => 'FactoryReset_' . time(),
            'Method' => 'FactoryReset',
        ];
        
        return $this->sendRPC($device, $rpcRequest);
    }
    
    /**
     * Send connection request to device
     */
    public function sendConnectionRequest(string $serialNumber): bool
    {
        $device = $this->getDevice($serialNumber);
        if (!$device || !isset($device['connection_request_url'])) {
            throw new \Exception("Device not found or Connection Request URL not available");
        }
        
        $url = $device['connection_request_url'];
        $username = $device['connection_request_username'] ?? '';
        $password = $device['connection_request_password'] ?? '';
        
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200 || $httpCode === 204;
        } catch (\Exception $e) {
            Log::error("Connection request failed", [
                'serial' => $serialNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get device information
     */
    public function getDevice(string $serialNumber): ?array
    {
        return Cache::get("tr069:device:{$serialNumber}");
    }
    
    /**
     * Get all managed devices
     */
    public function getAllDevices(): array
    {
        $keys = Cache::get('tr069:device_list', []);
        $devices = [];
        
        foreach ($keys as $serial) {
            $device = $this->getDevice($serial);
            if ($device) {
                $devices[] = $device;
            }
        }
        
        return $devices;
    }
    
    /**
     * Send RPC to device (via connection request)
     */
    private function sendRPC(array $device, array $rpcRequest): array
    {
        // Store pending RPC
        $serialNumber = $device['serial_number'];
        $rpcId = $rpcRequest['ID'];
        
        Cache::put("tr069:pending_rpc:{$serialNumber}:{$rpcId}", $rpcRequest, 300);
        
        // Trigger connection request
        $this->sendConnectionRequest($serialNumber);
        
        // Wait for response (in real implementation, this would be async)
        return [
            'Status' => 'Pending',
            'RPC_ID' => $rpcId,
            'Message' => 'RPC queued, waiting for device connection'
        ];
    }
    
    /**
     * Get ACS URL for devices to connect
     */
    public function getAcsUrl(): string
    {
        return $this->acsUrl;
    }
    
    /**
     * Get ACS credentials
     */
    public function getAcsCredentials(): array
    {
        return [
            'username' => $this->acsUsername,
            'password' => $this->acsPassword,
        ];
    }
}
