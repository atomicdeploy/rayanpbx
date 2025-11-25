<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Network Utilities Helper
 * 
 * Provides reusable functions for network-related operations:
 * - Detecting local network CIDRs from NICs
 * - IP whitelisting and validation
 * - CIDR matching
 */
class NetworkHelper
{
    /**
     * Cache key for local network CIDRs
     */
    protected const CACHE_KEY_LOCAL_CIDRS = 'local_network_cidrs';
    
    /**
     * Cache TTL in seconds (5 minutes)
     */
    protected const CACHE_TTL = 300;

    /**
     * Get all local network CIDRs from the machine's NICs
     * 
     * @return array Array of CIDR strings (e.g., ['192.168.1.0/24', '10.0.0.0/24'])
     */
    public static function getLocalNetworkCidrs(): array
    {
        return Cache::remember(self::CACHE_KEY_LOCAL_CIDRS, self::CACHE_TTL, function () {
            return self::detectLocalNetworkCidrs();
        });
    }

    /**
     * Detect local network CIDRs from system interfaces
     * 
     * @return array Array of CIDR strings
     */
    protected static function detectLocalNetworkCidrs(): array
    {
        $cidrs = [];

        try {
            // Try to get network interfaces using different methods
            $cidrs = array_merge($cidrs, self::getLinuxNetworkCidrs());
            
            // If no CIDRs found, fall back to parsing /etc/network/interfaces or ip addr
            if (empty($cidrs)) {
                $cidrs = self::getNetworkCidrsFromIpCommand();
            }

            // Always allow loopback
            $cidrs[] = '127.0.0.0/8';

            // Remove duplicates and empty values
            $cidrs = array_unique(array_filter($cidrs));

            Log::debug('Detected local network CIDRs', ['cidrs' => $cidrs]);

            return array_values($cidrs);
        } catch (\Throwable $e) {
            Log::error('Failed to detect local network CIDRs', ['error' => $e->getMessage()]);
            
            // Fall back to common private ranges if detection fails
            return [
                '127.0.0.0/8',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
            ];
        }
    }

    /**
     * Get network CIDRs on Linux systems using PHP's network functions
     * 
     * @return array
     */
    protected static function getLinuxNetworkCidrs(): array
    {
        $cidrs = [];

        // Try using PHP's built-in functions first
        if (function_exists('net_get_interfaces')) {
            $interfaces = @net_get_interfaces();
            
            if ($interfaces) {
                foreach ($interfaces as $name => $info) {
                    // Skip loopback in detection, we add it manually
                    if ($name === 'lo') {
                        continue;
                    }

                    // Check for IPv4 unicast addresses
                    if (isset($info['unicast']) && is_array($info['unicast'])) {
                        foreach ($info['unicast'] as $addr) {
                            if (isset($addr['address']) && isset($addr['netmask'])) {
                                $ip = $addr['address'];
                                $netmask = $addr['netmask'];

                                // Skip non-IPv4 addresses
                                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                                    continue;
                                }

                                // Convert netmask to CIDR prefix
                                $prefix = self::netmaskToCidrPrefix($netmask);
                                if ($prefix !== null) {
                                    $network = self::getNetworkAddress($ip, $prefix);
                                    if ($network) {
                                        $cidrs[] = "$network/$prefix";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $cidrs;
    }

    /**
     * Get network CIDRs using the `ip addr` command
     * 
     * Note: This command is completely hardcoded with no user input,
     * making it safe from command injection. The @ suppresses any
     * warnings if the command is not available.
     * 
     * @return array
     */
    protected static function getNetworkCidrsFromIpCommand(): array
    {
        $cidrs = [];

        // Execute hardcoded `ip addr` command (no user input - safe from injection)
        // Using proc_open for better control, but command is static
        $command = 'ip -4 addr show';
        $output = @shell_exec($command . ' 2>/dev/null');
        
        if ($output) {
            // Parse output for inet lines
            // Example: inet 192.168.1.100/24 brd 192.168.1.255 scope global eth0
            preg_match_all('/inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)\s+/', $output, $matches);
            
            if (!empty($matches[1]) && !empty($matches[2])) {
                foreach ($matches[1] as $i => $ip) {
                    $prefix = (int) $matches[2][$i];
                    
                    // Validate prefix is in valid range
                    if ($prefix < 0 || $prefix > 32) {
                        continue;
                    }
                    
                    // Validate IP format
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        continue;
                    }
                    
                    // Skip loopback (127.x.x.x)
                    if (strpos($ip, '127.') === 0) {
                        continue;
                    }
                    
                    $network = self::getNetworkAddress($ip, $prefix);
                    if ($network) {
                        $cidrs[] = "$network/$prefix";
                    }
                }
            }
        }

        return $cidrs;
    }

    /**
     * Convert subnet mask to CIDR prefix length
     * 
     * Validates that the netmask has contiguous 1 bits followed by contiguous 0 bits.
     * Invalid netmasks (like '255.0.255.0') will return null.
     * 
     * @param string $netmask The subnet mask (e.g., '255.255.255.0')
     * @return int|null The CIDR prefix length (e.g., 24) or null if invalid
     */
    public static function netmaskToCidrPrefix(string $netmask): ?int
    {
        $long = ip2long($netmask);
        
        if ($long === false) {
            return null;
        }

        // Convert to unsigned 32-bit integer for proper comparison
        $mask = $long & 0xFFFFFFFF;

        // Count the number of leading 1 bits
        $prefix = 0;
        $testBit = 0x80000000;
        
        while ($testBit > 0 && ($mask & $testBit)) {
            $prefix++;
            $testBit >>= 1;
        }

        // Validate that remaining bits are all 0 (contiguous netmask)
        // A valid netmask should have all 1s followed by all 0s
        $expectedMask = $prefix > 0 ? (-1 << (32 - $prefix)) & 0xFFFFFFFF : 0;
        
        if ($mask !== $expectedMask) {
            // Non-contiguous netmask (e.g., '255.0.255.0') - invalid
            return null;
        }

        return $prefix;
    }

    /**
     * Get the network address for an IP and CIDR prefix
     * 
     * @param string $ip The IP address
     * @param int $prefix The CIDR prefix length
     * @return string|null The network address or null if invalid
     */
    public static function getNetworkAddress(string $ip, int $prefix): ?string
    {
        $ipLong = ip2long($ip);
        
        if ($ipLong === false || $prefix < 0 || $prefix > 32) {
            return null;
        }

        $mask = -1 << (32 - $prefix);
        $networkLong = $ipLong & $mask;

        return long2ip($networkLong);
    }

    /**
     * Check if an IP address is within a CIDR range
     * 
     * @param string $ip The IP address to check
     * @param string $cidr The CIDR range (e.g., '192.168.1.0/24')
     * @return bool True if the IP is in the range
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        
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
     * Check if an IP is in one of the local network CIDRs
     * 
     * @param string $ip The IP address to check
     * @return bool True if the IP is in a local network
     */
    public static function isLocalNetworkIp(string $ip): bool
    {
        // Always allow localhost
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        $localCidrs = self::getLocalNetworkCidrs();
        
        foreach ($localCidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is whitelisted based on:
     * - Local network CIDRs (auto-detected from NICs)
     * - Registered VoIP phone IPs
     * - Additional whitelisted IPs/CIDRs from config
     * 
     * @param string $ip The IP address to check
     * @param array $registeredPhoneIps Array of registered phone IP addresses
     * @return bool True if the IP is whitelisted
     */
    public static function isIpWhitelisted(string $ip, array $registeredPhoneIps = []): bool
    {
        // Always allow localhost
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        // Check against local network CIDRs (auto-detected from NICs)
        if (self::isLocalNetworkIp($ip)) {
            return true;
        }

        // Check against registered phone IPs
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
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear the cached local network CIDRs
     * (Call this if NICs configuration changes)
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_LOCAL_CIDRS);
    }

    /**
     * Get detailed info about all detected local networks (for debugging)
     * 
     * @return array
     */
    public static function getNetworkInfo(): array
    {
        return [
            'local_cidrs' => self::getLocalNetworkCidrs(),
            'cached' => Cache::has(self::CACHE_KEY_LOCAL_CIDRS),
        ];
    }
}
