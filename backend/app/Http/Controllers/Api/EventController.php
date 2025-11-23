<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class EventController extends Controller
{
    /**
     * Get recent AMI events
     */
    public function index()
    {
        $events = Cache::get('recent_ami_events', []);
        
        return response()->json([
            'events' => $events,
            'count' => count($events),
        ]);
    }
    
    /**
     * Get extension registration events
     */
    public function registrations()
    {
        $events = Cache::get('recent_ami_events', []);
        
        // Filter for registration events
        $registrationEvents = array_filter($events, function($event) {
            return $event['type'] === 'extension.registration';
        });
        
        return response()->json([
            'events' => array_values($registrationEvents),
            'count' => count($registrationEvents),
        ]);
    }
    
    /**
     * Get call/ring events
     */
    public function calls()
    {
        $events = Cache::get('recent_ami_events', []);
        
        // Filter for call-related events
        $callEvents = array_filter($events, function($event) {
            return in_array($event['type'], ['extension.ringing', 'extension.hangup']);
        });
        
        return response()->json([
            'events' => array_values($callEvents),
            'count' => count($callEvents),
        ]);
    }
    
    /**
     * Get extension-specific registration status
     */
    public function extensionStatus($extension)
    {
        $cacheKey = "extension_registration_{$extension}";
        $status = Cache::get($cacheKey);
        
        if (!$status) {
            return response()->json([
                'extension' => $extension,
                'registered' => false,
                'status' => 'unknown',
            ]);
        }
        
        return response()->json([
            'extension' => $extension,
            'registered' => in_array($status['status'], ['Created', 'Reachable']),
            'status' => $status['status'],
            'uri' => $status['uri'] ?? null,
            'timestamp' => $status['timestamp'] ?? null,
        ]);
    }
    
    /**
     * Clear event history
     */
    public function clear()
    {
        Cache::forget('recent_ami_events');
        
        return response()->json([
            'message' => 'Event history cleared',
        ]);
    }
}
