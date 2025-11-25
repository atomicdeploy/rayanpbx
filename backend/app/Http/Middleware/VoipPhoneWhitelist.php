<?php

namespace App\Http\Middleware;

use App\Helpers\NetworkHelper;
use App\Services\GrandStreamProvisioningService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to whitelist VoIP phone IP addresses
 * 
 * This middleware restricts access to webhook endpoints to only:
 * - Local network IPs (auto-detected from the machine's NICs)
 * - Registered VoIP phone IPs (cached from Asterisk PJSIP)
 * - Additional whitelisted IPs/CIDRs from configuration
 */
class VoipPhoneWhitelist
{
    protected GrandStreamProvisioningService $provisioningService;

    public function __construct(GrandStreamProvisioningService $provisioningService)
    {
        $this->provisioningService = $provisioningService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = $request->ip();

        // Check if IP is whitelisted using NetworkHelper
        $registeredPhoneIps = $this->getRegisteredPhoneIps();
        
        if (!NetworkHelper::isIpWhitelisted($clientIp, $registeredPhoneIps)) {
            Log::warning('VoIP webhook access denied: IP not whitelisted', [
                'ip' => $clientIp,
                'uri' => $request->getRequestUri(),
                'local_cidrs' => NetworkHelper::getLocalNetworkCidrs(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Access denied: IP not whitelisted',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Get cache TTL for registered phone IPs (in seconds)
     */
    protected function getCacheTtl(): int
    {
        return (int) config('rayanpbx.voip_webhook_cache_ttl', 60);
    }

    /**
     * Get registered phone IPs from cache or Asterisk
     */
    protected function getRegisteredPhoneIps(): array
    {
        return Cache::remember('registered_phone_ips', $this->getCacheTtl(), function () {
            try {
                // Get phones from Asterisk via the provisioning service
                $result = $this->provisioningService->discoverPhones();
                
                $ips = [];
                
                // Extract IPs from discovered devices
                if (isset($result['devices']) && is_array($result['devices'])) {
                    foreach ($result['devices'] as $device) {
                        if (!empty($device['ip'])) {
                            $ips[] = $device['ip'];
                        }
                    }
                }
                
                // Extract IPs from registered phones
                if (isset($result['phones']) && is_array($result['phones'])) {
                    foreach ($result['phones'] as $phone) {
                        if (!empty($phone['ip'])) {
                            $ips[] = $phone['ip'];
                        }
                    }
                }
                
                Log::debug('Registered phone IPs loaded', ['count' => count($ips)]);
                
                return array_unique($ips);
            } catch (\Throwable $e) {
                Log::error('Failed to get registered phone IPs', [
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        });
    }

    /**
     * Clear the cached phone IPs (call when phones are added/removed)
     */
    public static function clearCache(): void
    {
        Cache::forget('registered_phone_ips');
        NetworkHelper::clearCache();
    }
}
