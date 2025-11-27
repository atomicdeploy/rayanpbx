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
     * Environment variable names for proxy configuration
     */
    private const ENV_HTTP_PROXY = 'http_proxy';

    private const ENV_HTTP_PROXY_UPPER = 'HTTP_PROXY';

    private const ENV_HTTPS_PROXY = 'https_proxy';

    private const ENV_HTTPS_PROXY_UPPER = 'HTTPS_PROXY';

    private const ENV_NO_PROXY = 'no_proxy';

    private const ENV_NO_PROXY_UPPER = 'NO_PROXY';

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
        $httpProxy = env(self::ENV_HTTP_PROXY) ?? env(self::ENV_HTTP_PROXY_UPPER);
        $httpsProxy = env(self::ENV_HTTPS_PROXY) ?? env(self::ENV_HTTPS_PROXY_UPPER);
        $noProxy = env(self::ENV_NO_PROXY) ?? env(self::ENV_NO_PROXY_UPPER);

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

        // Follow redirects by default (can be disabled with follow_redirects => false)
        $followRedirects = $options['follow_redirects'] ?? true;
        if ($followRedirects) {
            $request = $request->withOptions([
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => true,
                    'referer' => true,
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true,
                ],
            ]);
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
     * Create a client configured for GrandStream phone requests.
     *
     * GrandStream phones return HTTP/1.0 responses with potentially malformed
     * headers (e.g., "Set-Cookie: HttpOnly" without a value). This method
     * configures Guzzle to handle these non-standard responses.
     *
     * @param  string  $ip  Phone IP address (for Origin/Referer headers)
     * @param  string|null  $cookieHeader  Optional cookie header value
     * @param  int  $timeout  Timeout in seconds (default: 15)
     */
    public function grandstreamClient(string $ip, ?string $cookieHeader = null, int $timeout = 15): PendingRequest
    {
        $headers = [
            'User-Agent' => $this->getUserAgent(),
            'Origin' => "http://{$ip}",
            'Referer' => "http://{$ip}/",
        ];

        if ($cookieHeader !== null) {
            $headers['Cookie'] = $cookieHeader;
        }

        $request = Http::withHeaders($headers)
            ->timeout($timeout)
            ->connectTimeout(10);

        // Apply proxy configuration if available
        $proxyConfig = $this->getProxyConfig();
        if ($proxyConfig) {
            $request = $request->withOptions([
                'proxy' => $proxyConfig,
            ]);
        }

        // Configure Guzzle to be lenient with malformed headers
        $request = $request->withOptions([
            'http_errors' => false,
            // Don't follow redirects (GrandStream phones use 301 for some endpoints)
            'allow_redirects' => false,
        ]);

        return $request;
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

        return $this->executeRequest($client, $method, $url, $data);
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

        return $this->executeRequest($client, $method, $url);
    }

    /**
     * Execute HTTP request based on method
     *
     * @param  PendingRequest  $client  Configured HTTP client
     * @param  string  $method  HTTP method
     * @param  string  $url  Target URL
     * @param  array  $data  Request data (used for GET query params or POST body)
     * @return \Illuminate\Http\Client\Response
     */
    protected function executeRequest(PendingRequest $client, string $method, string $url, array $data = [])
    {
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

    /**
     * Make an HTTP request using PHP's native stream functions.
     *
     * This method provides an alternative to Guzzle for devices that return
     * malformed HTTP headers (e.g., GrandStream phones with "Set-Cookie: HttpOnly"
     * without a value). It uses PHP's file_get_contents with stream context.
     *
     * Features:
     * - Consistent User-Agent header (RayanPBX/version)
     * - Automatic proxy support via environment variables
     * - Configurable timeout
     * - Error handling with detailed error info
     *
     * @param  string  $url  Full URL to request
     * @param  string  $method  HTTP method (GET, POST, etc.)
     * @param  array  $headers  Additional headers (key => value)
     * @param  string|null  $body  Request body content
     * @param  int|null  $timeout  Timeout in seconds (default: class timeout)
     * @return array{success: bool, body?: string, status_code?: int, error?: string, headers?: array}
     */
    public function nativeRequest(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        ?int $timeout = null
    ): array {
        $timeout = $timeout ?? $this->timeout;

        // Build headers array with User-Agent
        $headerStrings = [];
        $hasUserAgent = false;

        foreach ($headers as $name => $value) {
            $headerStrings[] = "{$name}: {$value}";
            if (strtolower($name) === 'user-agent') {
                $hasUserAgent = true;
            }
        }

        // Add User-Agent if not provided
        if (! $hasUserAgent) {
            $headerStrings[] = 'User-Agent: '.$this->getUserAgent();
        }

        // Build stream context options
        $httpOptions = [
            'method' => strtoupper($method),
            'header' => $headerStrings,
            'timeout' => $timeout,
            'ignore_errors' => true, // Get response body even on HTTP errors
        ];

        if ($body !== null) {
            $httpOptions['content'] = $body;
        }

        // Add proxy configuration
        $proxyConfig = $this->getProxyConfig();
        if ($proxyConfig) {
            // Determine which proxy to use based on URL scheme
            $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'http';
            $proxyUrl = null;

            if ($scheme === 'https' && isset($proxyConfig['https'])) {
                $proxyUrl = $proxyConfig['https'];
            } elseif (isset($proxyConfig['http'])) {
                $proxyUrl = $proxyConfig['http'];
            }

            if ($proxyUrl) {
                $httpOptions['proxy'] = $proxyUrl;
                $httpOptions['request_fulluri'] = true;
            }
        }

        $context = stream_context_create(['http' => $httpOptions]);

        // Make the request
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();

            return [
                'success' => false,
                'error' => $error['message'] ?? 'Request failed',
            ];
        }

        // Parse response headers from $http_response_header (set by file_get_contents)
        $statusCode = 200;
        $responseHeaders = [];

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d+\.?\d*\s+(\d+)/', $header, $matches)) {
                    $statusCode = (int) $matches[1];
                } elseif (str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $responseHeaders[trim($name)] = trim($value);
                }
            }
        }

        return [
            'success' => true,
            'body' => $response,
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Make a native POST request with form data.
     *
     * Convenience method for form-encoded POST requests using native streams.
     *
     * @param  string  $url  Full URL to request
     * @param  array|string  $data  Form data (array will be encoded, string used as-is)
     * @param  array  $headers  Additional headers
     * @param  int|null  $timeout  Timeout in seconds
     * @return array{success: bool, body?: string, status_code?: int, error?: string}
     */
    public function nativePost(string $url, array|string $data = [], array $headers = [], ?int $timeout = null): array
    {
        // Ensure Content-Type is set for form data
        $hasContentType = false;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $hasContentType = true;
                break;
            }
        }

        if (! $hasContentType) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        $body = is_array($data) ? http_build_query($data) : $data;

        return $this->nativeRequest($url, 'POST', $headers, $body, $timeout);
    }

    /**
     * Make a native GET request.
     *
     * Convenience method for GET requests using native streams.
     *
     * @param  string  $url  Full URL to request
     * @param  array  $query  Query parameters (will be appended to URL)
     * @param  array  $headers  Additional headers
     * @param  int|null  $timeout  Timeout in seconds
     * @return array{success: bool, body?: string, status_code?: int, error?: string}
     */
    public function nativeGet(string $url, array $query = [], array $headers = [], ?int $timeout = null): array
    {
        if (! empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $this->nativeRequest($url, 'GET', $headers, null, $timeout);
    }
}
