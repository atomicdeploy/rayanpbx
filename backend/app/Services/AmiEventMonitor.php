<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AmiEventMonitor
{
    private $socket;
    private $amiHost;
    private $amiPort;
    private $amiUsername;
    private $amiSecret;
    private $running = false;
    private $eventCallbacks = [];
    
    public function __construct()
    {
        $this->amiHost = config('rayanpbx.asterisk.ami_host', '127.0.0.1');
        $this->amiPort = config('rayanpbx.asterisk.ami_port', 5038);
        $this->amiUsername = config('rayanpbx.asterisk.ami_username', 'admin');
        $this->amiSecret = config('rayanpbx.asterisk.ami_secret', '');
    }
    
    /**
     * Connect to AMI and login
     */
    private function connect()
    {
        try {
            $this->socket = fsockopen($this->amiHost, $this->amiPort, $errno, $errstr, 5);
            if (!$this->socket) {
                throw new Exception("Cannot connect to AMI: $errstr ($errno)");
            }
            
            // Set non-blocking mode for event monitoring
            stream_set_blocking($this->socket, false);
            
            // Read welcome banner
            sleep(1);
            $this->readResponse();
            
            // Login
            $this->sendCommand([
                'Action' => 'Login',
                'Username' => $this->amiUsername,
                'Secret' => $this->amiSecret,
                'Events' => 'on'
            ]);
            
            sleep(1);
            $response = $this->readResponse();
            
            if (!str_contains($response, 'Success')) {
                throw new Exception("AMI login failed");
            }
            
            return true;
        } catch (Exception $e) {
            Log::error('AMI connection error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send AMI command
     */
    private function sendCommand(array $command)
    {
        if (!$this->socket) {
            return false;
        }
        
        $message = '';
        foreach ($command as $key => $value) {
            $message .= "$key: $value\r\n";
        }
        $message .= "\r\n";
        
        return fwrite($this->socket, $message);
    }
    
    /**
     * Read AMI response/event
     */
    private function readResponse($timeout = 2)
    {
        if (!$this->socket) {
            return '';
        }
        
        $response = '';
        $startTime = time();
        
        while (time() - $startTime < $timeout) {
            $line = fgets($this->socket);
            if ($line === false) {
                usleep(100000); // 100ms
                continue;
            }
            
            $response .= $line;
            
            // Check for end of message
            if (trim($line) == '') {
                break;
            }
        }
        
        return $response;
    }
    
    /**
     * Parse AMI event into array
     */
    private function parseEvent($eventText)
    {
        $event = [];
        $lines = explode("\r\n", $eventText);
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $event[trim($parts[0])] = trim($parts[1]);
            }
        }
        
        return $event;
    }
    
    /**
     * Register callback for specific event type
     */
    public function on($eventType, callable $callback)
    {
        if (!isset($this->eventCallbacks[$eventType])) {
            $this->eventCallbacks[$eventType] = [];
        }
        
        $this->eventCallbacks[$eventType][] = $callback;
    }
    
    /**
     * Trigger callbacks for event
     */
    private function triggerCallbacks($eventType, $eventData)
    {
        if (isset($this->eventCallbacks[$eventType])) {
            foreach ($this->eventCallbacks[$eventType][] as $callback) {
                call_user_func($callback, $eventData);
            }
        }
        
        // Also trigger wildcard callbacks
        if (isset($this->eventCallbacks['*'])) {
            foreach ($this->eventCallbacks['*'] as $callback) {
                call_user_func($callback, $eventType, $eventData);
            }
        }
    }
    
    /**
     * Start monitoring AMI events
     */
    public function start()
    {
        if (!$this->connect()) {
            return false;
        }
        
        $this->running = true;
        Log::info('AMI Event Monitor started');
        
        while ($this->running) {
            $eventText = $this->readResponse(1);
            
            if (empty($eventText)) {
                usleep(100000); // 100ms
                continue;
            }
            
            // Parse event
            $event = $this->parseEvent($eventText);
            
            if (!isset($event['Event'])) {
                continue;
            }
            
            $eventType = $event['Event'];
            
            // Log important events
            if (in_array($eventType, ['PeerStatus', 'ContactStatus', 'Newchannel', 'Newstate', 'Hangup'])) {
                Log::info("AMI Event: {$eventType}", $event);
            }
            
            // Handle specific events
            $this->handleEvent($eventType, $event);
            
            // Trigger callbacks
            $this->triggerCallbacks($eventType, $event);
        }
        
        $this->disconnect();
        return true;
    }
    
    /**
     * Stop monitoring
     */
    public function stop()
    {
        $this->running = false;
    }
    
    /**
     * Disconnect from AMI
     */
    private function disconnect()
    {
        if ($this->socket) {
            $this->sendCommand(['Action' => 'Logoff']);
            fclose($this->socket);
            $this->socket = null;
        }
        
        Log::info('AMI Event Monitor stopped');
    }
    
    /**
     * Handle specific event types
     */
    private function handleEvent($eventType, $event)
    {
        switch ($eventType) {
            case 'ContactStatus':
                $this->handleContactStatus($event);
                break;
                
            case 'PeerStatus':
                $this->handlePeerStatus($event);
                break;
                
            case 'Newchannel':
                $this->handleNewChannel($event);
                break;
                
            case 'Newstate':
                $this->handleNewState($event);
                break;
                
            case 'Hangup':
                $this->handleHangup($event);
                break;
        }
    }
    
    /**
     * Handle ContactStatus event (PJSIP registration)
     */
    private function handleContactStatus($event)
    {
        $uri = $event['URI'] ?? '';
        $status = $event['ContactStatus'] ?? '';
        
        // Extract endpoint name from AOR
        if (isset($event['AOR']) && preg_match('/^(\d+)$/', $event['AOR'], $matches)) {
            $extension = $matches[1];
            
            // Cache registration status
            $cacheKey = "extension_registration_{$extension}";
            Cache::put($cacheKey, [
                'status' => $status,
                'uri' => $uri,
                'timestamp' => now()->toIso8601String(),
            ], 3600);
            
            // Broadcast event via WebSocket
            $this->broadcastEvent('extension.registration', [
                'extension' => $extension,
                'status' => $status,
                'uri' => $uri,
                'registered' => $status === 'Created' || $status === 'Reachable',
            ]);
            
            Log::info("Extension {$extension} registration status: {$status}");
        }
    }
    
    /**
     * Handle PeerStatus event (legacy SIP)
     */
    private function handlePeerStatus($event)
    {
        $peer = $event['Peer'] ?? '';
        $status = $event['PeerStatus'] ?? '';
        
        if (preg_match('/SIP\/(\d+)/', $peer, $matches)) {
            $extension = $matches[1];
            
            Log::info("Peer {$extension} status: {$status}");
        }
    }
    
    /**
     * Handle new channel event (call started)
     */
    private function handleNewChannel($event)
    {
        $channel = $event['Channel'] ?? '';
        $callerIdNum = $event['CallerIDNum'] ?? '';
        $exten = $event['Exten'] ?? '';
        
        // Cache active channel
        $cacheKey = "channel_{$channel}";
        Cache::put($cacheKey, [
            'caller' => $callerIdNum,
            'extension' => $exten,
            'state' => 'Down',
            'timestamp' => now()->toIso8601String(),
        ], 3600);
        
        Log::info("New channel: {$channel}, Caller: {$callerIdNum}, Exten: {$exten}");
    }
    
    /**
     * Handle channel state change (including ring)
     */
    private function handleNewState($event)
    {
        $channel = $event['Channel'] ?? '';
        $channelState = $event['ChannelState'] ?? '';
        $channelStateDesc = $event['ChannelStateDesc'] ?? '';
        $callerIdNum = $event['CallerIDNum'] ?? '';
        $exten = $event['Exten'] ?? '';
        
        // Update channel cache
        $cacheKey = "channel_{$channel}";
        $channelData = Cache::get($cacheKey, []);
        $channelData['state'] = $channelStateDesc;
        $channelData['timestamp'] = now()->toIso8601String();
        Cache::put($cacheKey, $channelData, 3600);
        
        // Detect ringing state
        if ($channelStateDesc === 'Ringing' || $channelStateDesc === 'Ring') {
            $this->broadcastEvent('extension.ringing', [
                'channel' => $channel,
                'caller' => $callerIdNum,
                'destination' => $exten,
                'state' => $channelStateDesc,
            ]);
            
            Log::info("Extension {$exten} ringing from {$callerIdNum}");
        }
    }
    
    /**
     * Handle hangup event
     */
    private function handleHangup($event)
    {
        $channel = $event['Channel'] ?? '';
        $cause = $event['Cause'] ?? '';
        $causeText = $event['Cause-txt'] ?? '';
        
        // Remove from cache
        $cacheKey = "channel_{$channel}";
        Cache::forget($cacheKey);
        
        $this->broadcastEvent('extension.hangup', [
            'channel' => $channel,
            'cause' => $cause,
            'cause_text' => $causeText,
        ]);
        
        Log::info("Hangup: {$channel}, Cause: {$causeText}");
    }
    
    /**
     * Broadcast event to WebSocket clients
     */
    private function broadcastEvent($eventType, $data)
    {
        // Store in cache for WebSocket server to pick up
        $cacheKey = "websocket_event_" . uniqid();
        Cache::put($cacheKey, [
            'type' => $eventType,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ], 60);
        
        // Also store in recent events list
        $recentEvents = Cache::get('recent_ami_events', []);
        $recentEvents[] = [
            'type' => $eventType,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ];
        
        // Keep only last 100 events
        if (count($recentEvents) > 100) {
            $recentEvents = array_slice($recentEvents, -100);
        }
        
        Cache::put('recent_ami_events', $recentEvents, 3600);
    }
}
