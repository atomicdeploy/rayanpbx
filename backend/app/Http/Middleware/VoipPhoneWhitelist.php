<?php

namespace App\Http\Middleware;

use App\Services\GrandStreamProvisioningService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to whitelist VoIP phone IP addresses
 * 
 * This middleware restricts access to webhook endpoints to only registered VoIP phone IPs.
 * It uses cached phone IPs from Asterisk PJSIP and also allows local/private network IPs.
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

        // Check if IP is whitelisted
        if (!$this->isIpWhitelisted($clientIp)) {
            Log::warning('VoIP webhook access denied: IP not whitelisted', [
                'ip' => $clientIp,
                'uri' => $request->getRequestUri(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Access denied: IP not whitelisted',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Check if an IP address is whitelisted
     */
    protected function isIpWhitelisted(string $ip): bool
    {
        // Always allow localhost
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        // Allow common private network ranges (configurable)
        if ($this->isPrivateIp($ip) && $this->shouldAllowPrivateNetworks()) {
            return true;
        }

        // Check against registered phone IPs
        $registeredPhoneIps = $this->getRegisteredPhoneIps();
        if (in_array($ip, $registeredPhoneIps, true)) {
            return true;
        }

        // Check against additional whitelisted IPs from config
        $additionalIps = config('rayanpbx.voip_webhook_whitelist', []);
        if (in_array($ip, $additionalIps, true)) {
            return true;
        }

        // Check CIDR ranges from config
        $cidrRanges = config('rayanpbx.voip_webhook_cidr_whitelist', []);
        foreach ($cidrRanges as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is in a private network range
     */
    protected function isPrivateIp(string $ip): bool
    {
        // Check if the IP is a valid IPv4 address first
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // Private network ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
        $privateRanges = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ];

        foreach ($privateRanges as $range) {
            if ($this->ipInCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if private networks should be allowed (for development/internal deployment)
     */
    protected function shouldAllowPrivateNetworks(): bool
    {
        return config('rayanpbx.voip_webhook_allow_private', true);
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
     * Check if an IP is within a CIDR range
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int)$bits;
        
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        $subnetLong &= $mask;

        return ($ipLong & $mask) === $subnetLong;
    }

    /**
     * Clear the cached phone IPs (call when phones are added/removed)
     */
    public static function clearCache(): void
    {
        Cache::forget('registered_phone_ips');
    }
}
