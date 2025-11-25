<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Unified HTTP Client Service
 *
 * Provides a centralized HTTP client with:
 * - Custom User-Agent header (RayanPBX/version)
 * - Automatic proxy support via http_proxy/HTTPS_PROXY env vars
 * - Consistent timeout and retry settings
 */
class HttpClientService
{
    /**
     * Default timeout in seconds
     */
    protected int $timeout;

    /**
     * Default connection timeout in seconds
     */
    protected int $connectTimeout;

    public function __construct()
    {
        $this->timeout = $this->getConfigValue('rayanpbx.http.timeout', 30);
        $this->connectTimeout = $this->getConfigValue('rayanpbx.http.connect_timeout', 10);
    }

    /**
     * Get configuration value, with fallback if Laravel not bootstrapped
     */
    protected function getConfigValue(string $key, mixed $default): mixed
    {
        try {
            if (function_exists('config')) {
                return config($key, $default);
            }
        } catch (\Throwable $e) {
            // Laravel not fully bootstrapped
        }

        return $default;
    }

    /**
     * Get the application version from VERSION file
     */
    protected function getVersion(): string
    {
        // Try using base_path() if available (Laravel context)
        try {
            if (function_exists('base_path')) {
                $versionFile = base_path('../VERSION');
                if (file_exists($versionFile)) {
                    return trim(file_get_contents($versionFile));
                }
            }
        } catch (\Throwable $e) {
            // Laravel not fully bootstrapped, fall through
        }

        // Fallback: try finding VERSION file relative to this file
        $versionFile = dirname(__DIR__, 3).'/VERSION';
        if (file_exists($versionFile)) {
            return trim(file_get_contents($versionFile));
        }

        return '2.0.0';
    }

    /**
     * Get the User-Agent string for HTTP requests
     */
    public function getUserAgent(): string
    {
        $version = $this->getVersion();
        $phpVersion = PHP_VERSION;

        return "RayanPBX/{$version} (PHP/{$phpVersion})";
    }

    /**
     * Get proxy configuration from environment variables
     *
     * Supports:
     * - http_proxy / HTTP_PROXY for HTTP requests
     * - https_proxy / HTTPS_PROXY for HTTPS requests
     * - no_proxy / NO_PROXY for exclusions
     */
    protected function getProxyConfig(): ?array
    {
        $httpProxy = env('http_proxy') ?? env('HTTP_PROXY');
        $httpsProxy = env('https_proxy') ?? env('HTTPS_PROXY');
        $noProxy = env('no_proxy') ?? env('NO_PROXY');

        if (! $httpProxy && ! $httpsProxy) {
            return null;
        }

        $config = [];

        if ($httpProxy) {
            $config['http'] = $httpProxy;
        }

        if ($httpsProxy) {
            $config['https'] = $httpsProxy;
        }

        if ($noProxy) {
            $config['no'] = array_map('trim', explode(',', $noProxy));
        }

        return $config;
    }

    /**
     * Create a configured HTTP client instance
     *
     * @param  array  $options  Additional options to merge
     */
    public function client(array $options = []): PendingRequest
    {
        $request = Http::withHeaders([
            'User-Agent' => $this->getUserAgent(),
        ]);

        // Apply timeout settings
        $timeout = $options['timeout'] ?? $this->timeout;
        $connectTimeout = $options['connect_timeout'] ?? $this->connectTimeout;
        $request = $request->timeout($timeout)->connectTimeout($connectTimeout);

        // Apply proxy configuration if available
        $proxyConfig = $this->getProxyConfig();
        if ($proxyConfig) {
            $request = $request->withOptions([
                'proxy' => $proxyConfig,
            ]);
        }

        // Apply any additional headers
        if (isset($options['headers']) && is_array($options['headers'])) {
            $request = $request->withHeaders($options['headers']);
        }

        return $request;
    }

    /**
     * Create a client configured for local network requests (phones, devices)
     * Uses shorter timeouts suitable for LAN communication
     *
     * @param  int  $timeout  Timeout in seconds (default: 5)
     */
    public function localClient(int $timeout = 5): PendingRequest
    {
        return $this->client([
            'timeout' => $timeout,
            'connect_timeout' => 3,
        ]);
    }

    /**
     * Make a GET request
     *
     * @param  array  $query  Query parameters
     * @param  array  $options  Additional options
     * @return \Illuminate\Http\Client\Response
     */
    public function get(string $url, array $query = [], array $options = [])
    {
        return $this->client($options)->get($url, $query);
    }

    /**
     * Make a POST request
     *
     * @param  array  $options  Additional options
     * @return \Illuminate\Http\Client\Response
     */
    public function post(string $url, array $data = [], array $options = [])
    {
        return $this->client($options)->post($url, $data);
    }

    /**
     * Make a HEAD request (useful for checking server headers)
     *
     * @param  array  $options  Additional options
     * @return \Illuminate\Http\Client\Response
     */
    public function head(string $url, array $options = [])
    {
        return $this->client($options)->head($url);
    }

    /**
     * Make a request with basic authentication
     *
     * @param  string  $method  HTTP method (GET, POST, etc.)
     * @param  array  $data  Request data
     * @param  array  $options  Additional options
     * @return \Illuminate\Http\Client\Response
     */
    public function withBasicAuth(
        string $url,
        string $username,
        string $password,
        string $method = 'GET',
        array $data = [],
        array $options = []
    ) {
        $client = $this->client($options)->withBasicAuth($username, $password);

        return match (strtoupper($method)) {
            'GET' => $client->get($url, $data),
            'POST' => $client->post($url, $data),
            'PUT' => $client->put($url, $data),
            'PATCH' => $client->patch($url, $data),
            'DELETE' => $client->delete($url, $data),
            default => $client->get($url, $data),
        };
    }

    /**
     * Make a request with digest authentication
     * Note: Laravel's HTTP client doesn't directly support digest auth,
     * so we use Guzzle options directly
     *
     * @param  string  $method  HTTP method
     * @param  array  $options  Additional options
     * @return \Illuminate\Http\Client\Response
     */
    public function withDigestAuth(
        string $url,
        string $username,
        string $password,
        string $method = 'GET',
        array $options = []
    ) {
        $client = $this->client($options)->withOptions([
            'auth' => [$username, $password, 'digest'],
        ]);

        return match (strtoupper($method)) {
            'GET' => $client->get($url),
            'POST' => $client->post($url),
            'PUT' => $client->put($url),
            'PATCH' => $client->patch($url),
            'DELETE' => $client->delete($url),
            default => $client->get($url),
        };
    }

    /**
     * Check if proxy is configured
     */
    public function hasProxy(): bool
    {
        return $this->getProxyConfig() !== null;
    }

    /**
     * Get timeout setting
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set timeout setting
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Get connect timeout setting
     */
    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    /**
     * Set connect timeout setting
     */
    public function setConnectTimeout(int $connectTimeout): self
    {
        $this->connectTimeout = $connectTimeout;

        return $this;
    }
}
