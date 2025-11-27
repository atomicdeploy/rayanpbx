<?php

namespace App\Services;

use App\Models\PhoneSession;
use App\Models\VoipPhone;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * GrandStream Session Authentication Service
 *
 * Provides session-based authentication for GrandStream phones.
 * This internal service handles the /cgi-bin/dologin endpoint functionality
 * to establish and maintain persistent sessions instead of including
 * credentials in every request.
 *
 * Authentication flow:
 * 1. POST to /cgi-bin/dologin with username=admin&password=<url-encoded-password>
 * 2. Include Cookie: HttpOnly header
 * 3. Get session ID (sid) and session-role from response
 * 4. Use cookies: session-identity=<sid> and session-role=admin for API requests
 *
 * API Request format (for api.values.get):
 * - POST /cgi-bin/api.values.get
 * - Content-Type: application/x-www-form-urlencoded
 * - Body: request=param1:param2:param3&sid=<sid>
 * - Cookies: HttpOnly; session-identity=<sid>; session-role=admin
 *
 * Based on GrandStream GXP series (tested with GXP1625).
 */
class GrandStreamSessionService
{
    protected HttpClientService $httpClient;

    /**
     * Default session expiration in seconds (30 minutes)
     */
    protected const DEFAULT_SESSION_TTL = 1800;

    /**
     * API endpoints for GrandStream devices
     */
    protected const ENDPOINT_DOLOGIN = '/cgi-bin/dologin';

    protected const ENDPOINT_API_VALUES_GET = '/cgi-bin/api.values.get';

    protected const ENDPOINT_API_VALUES_POST = '/cgi-bin/api.values.post';

    /**
     * Common GrandStream parameter names for device info
     */
    protected const DEVICE_INFO_PARAMS = [
        'vendor_name',
        'vendor_fullname',
        'phone_model',
        'core_version',
        'base_version',
        'boot_version',
        'prog_version',
        'dsp_version',
    ];

    public function __construct(?HttpClientService $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClientService;
    }

    /**
     * Perform login to a GrandStream phone and store the session.
     *
     * @param  VoipPhone  $phone  The phone to authenticate with
     * @param  array|null  $credentials  Optional credentials override (username, password)
     * @return array Result containing success status, session info, and any errors
     */
    public function login(VoipPhone $phone, ?array $credentials = null): array
    {
        $credentials = $credentials ?? $phone->getCredentialsForApi();
        $username = $credentials['username'] ?? 'admin';
        $password = $credentials['password'] ?? '';

        if (empty($password)) {
            return [
                'success' => false,
                'error' => 'No password provided for authentication',
            ];
        }

        // Revoke any existing sessions for this phone
        PhoneSession::revokeAllForPhone($phone->id);

        // Try the dologin endpoint (the correct method based on testing)
        $result = $this->performDologin($phone->ip, $username, $password);

        if ($result['success']) {
            // Store the session in database
            $session = $this->storeSession($phone, $result);

            return [
                'success' => true,
                'session_id' => $session->id,
                'sid' => $result['sid'],
                'role' => $result['role'],
                'expires_at' => $session->expires_at?->toIso8601String(),
                'message' => 'Successfully authenticated with phone',
            ];
        }

        Log::warning('GrandStream login failed', [
            'phone_ip' => $phone->ip,
            'error' => $result['error'] ?? 'Unknown error',
        ]);

        return $result;
    }

    /**
     * Perform authentication via /cgi-bin/dologin endpoint.
     *
     * This is the correct GrandStream authentication method:
     * POST /cgi-bin/dologin with form data: username=admin&password=<password>
     * Must include Cookie: HttpOnly header
     * Must include Origin and Referer headers
     *
     * Response: { "response": "success", "body": { "sid": "xxx", "role": "admin", "defaultAuth": false } }
     */
    protected function performDologin(string $ip, string $username, string $password): array
    {
        try {
            // Use Guzzle directly for precise control over the request
            $client = new Client([
                'timeout' => 15,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);

            $response = $client->post("http://{$ip}" . self::ENDPOINT_DOLOGIN, [
                'form_params' => [
                    'username' => $username,
                    'password' => $password,
                ],
                'headers' => [
                    'Accept' => '*/*',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => 'HttpOnly',
                    'Origin' => "http://{$ip}",
                    'Pragma' => 'no-cache',
                    'Referer' => "http://{$ip}/",
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($statusCode !== 200) {
                return [
                    'success' => false,
                    'error' => "Login failed with status {$statusCode}",
                    'body' => $body,
                ];
            }

            // Check for Forbidden response
            if (str_contains($body, 'Forbidden')) {
                return [
                    'success' => false,
                    'error' => 'Login forbidden - invalid credentials or headers',
                    'body' => $body,
                ];
            }

            // Parse JSON response
            $data = json_decode($body, true);

            if (!$data) {
                return [
                    'success' => false,
                    'error' => 'Invalid JSON response',
                    'body' => $body,
                ];
            }

            if (($data['response'] ?? '') !== 'success') {
                return [
                    'success' => false,
                    'error' => 'Login response was not successful',
                    'response_data' => $data,
                ];
            }

            $sid = $data['body']['sid'] ?? null;
            $role = $data['body']['role'] ?? 'admin';

            if (!$sid) {
                return [
                    'success' => false,
                    'error' => 'No session ID received',
                    'response_data' => $data,
                ];
            }

            // Extract cookies from response headers
            // Correct cookie names: session-identity=<sid> and session-role=admin
            $cookies = [
                'HttpOnly' => '',
                'session-identity' => $sid,  // This is the correct cookie name for session ID
                'session-role' => $role,
            ];

            // Add any Set-Cookie headers
            foreach ($response->getHeader('Set-Cookie') as $setCookie) {
                $parts = explode(';', $setCookie);
                if (!empty($parts[0]) && str_contains($parts[0], '=')) {
                    [$name, $value] = explode('=', $parts[0], 2);
                    $name = trim($name);
                    if ($name && $name !== 'HttpOnly') {
                        $cookies[$name] = trim($value);
                    }
                }
            }

            return [
                'success' => true,
                'method' => 'dologin',
                'sid' => $sid,
                'role' => $role,
                'cookies' => $cookies,
                'response_data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('GrandStream dologin exception', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Exception during login: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Store the session in the database.
     */
    protected function storeSession(VoipPhone $phone, array $authResult): PhoneSession
    {
        $expiresAt = now()->addSeconds(self::DEFAULT_SESSION_TTL);

        $session = PhoneSession::create([
            'voip_phone_id' => $phone->id,
            'session_id' => $authResult['sid'] ?? null,
            'challenge' => null,
            'token' => $authResult['role'] ?? null,
            'is_active' => true,
            'authenticated_at' => now(),
            'expires_at' => $expiresAt,
            'last_used_at' => now(),
        ]);

        // Store cookies if present (encrypted)
        if (!empty($authResult['cookies'])) {
            $session->setCookiesFromArray($authResult['cookies']);
            $session->save();
        }

        return $session;
    }

    /**
     * Get a valid session for a phone.
     */
    public function getSession(VoipPhone $phone): ?PhoneSession
    {
        $session = PhoneSession::getValidSession($phone->id);

        if ($session) {
            $session->markUsed();
            return $session;
        }

        return null;
    }

    /**
     * Get or create a valid session for a phone.
     */
    public function getOrCreateSession(VoipPhone $phone, ?array $credentials = null): array
    {
        // Check for existing valid session
        $session = $this->getSession($phone);

        if ($session) {
            return [
                'success' => true,
                'session' => $session,
                'reused' => true,
            ];
        }

        // No valid session, create a new one
        $loginResult = $this->login($phone, $credentials);

        if ($loginResult['success']) {
            $session = PhoneSession::find($loginResult['session_id']);

            return [
                'success' => true,
                'session' => $session,
                'reused' => false,
            ];
        }

        return [
            'success' => false,
            'error' => $loginResult['error'] ?? 'Failed to create session',
        ];
    }

    /**
     * Logout/revoke a session.
     */
    public function logout(VoipPhone $phone): bool
    {
        return PhoneSession::revokeAllForPhone($phone->id) > 0;
    }

    /**
     * Make an authenticated API request using stored session.
     */
    public function authenticatedRequest(
        VoipPhone $phone,
        string $endpoint,
        array $params = [],
        string $method = 'GET'
    ): array {
        $sessionResult = $this->getOrCreateSession($phone);

        if (!$sessionResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to establish session: ' . ($sessionResult['error'] ?? 'Unknown error'),
            ];
        }

        /** @var PhoneSession $session */
        $session = $sessionResult['session'];

        return $this->executeAuthenticatedRequest($phone->ip, $endpoint, $params, $method, $session);
    }

    /**
     * Execute an authenticated request with session cookies.
     */
    protected function executeAuthenticatedRequest(
        string $ip,
        string $endpoint,
        array $params,
        string $method,
        PhoneSession $session
    ): array {
        try {
            $client = new Client([
                'timeout' => 15,
                'http_errors' => false,
            ]);

            $cookies = $session->getCookiesArray();
            $cookieString = implode('; ', array_map(
                fn($k, $v) => $v ? "{$k}={$v}" : $k,
                array_keys($cookies),
                $cookies
            ));

            $headers = [
                'Accept' => 'application/json',
                'Cookie' => $cookieString,
                'Origin' => "http://{$ip}",
                'Referer' => "http://{$ip}/",
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ];

            $url = "http://{$ip}{$endpoint}";

            if ($method === 'GET') {
                if (!empty($params)) {
                    $url .= '?' . http_build_query($params);
                }
                $response = $client->get($url, ['headers' => $headers]);
            } else {
                $headers['Content-Type'] = 'application/json';
                $response = $client->post($url, [
                    'headers' => $headers,
                    'json' => ['request' => $params],
                ]);
            }

            $body = (string) $response->getBody();

            // Check for session expiration
            if (str_contains($body, 'session-expired')) {
                // Session expired, invalidate it
                $session->revoke();

                return [
                    'success' => false,
                    'error' => 'Session expired',
                    'session_expired' => true,
                ];
            }

            $data = json_decode($body, true);

            return [
                'success' => $response->getStatusCode() === 200,
                'status_code' => $response->getStatusCode(),
                'body' => $body,
                'data' => $data['body'] ?? $data ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test if we can authenticate with a phone (without storing session).
     */
    public function testAuthentication(string $ip, string $username, string $password): array
    {
        $result = $this->performDologin($ip, $username, $password);

        return [
            'success' => $result['success'],
            'sid' => $result['sid'] ?? null,
            'role' => $result['role'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * Clean up expired sessions (can be run via scheduler).
     */
    public function cleanupExpiredSessions(): int
    {
        return PhoneSession::cleanupExpired();
    }

    /**
     * Make a direct API request to a phone using existing session cookies.
     *
     * @param  string  $ip  Phone IP address
     * @param  string  $action  API action name
     * @param  array  $cookies  Session cookies (HttpOnly, session-role, sid)
     * @return array
     */
    public function apiRequest(string $ip, string $action, array $cookies): array
    {
        try {
            $client = new Client([
                'timeout' => 15,
                'http_errors' => false,
            ]);

            $cookieString = implode('; ', array_map(
                fn($k, $v) => $v ? "{$k}={$v}" : $k,
                array_keys($cookies),
                $cookies
            ));

            $response = $client->post("http://{$ip}" . self::ENDPOINT_API_VALUES_GET, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Cookie' => $cookieString,
                    'Origin' => "http://{$ip}",
                    'Referer' => "http://{$ip}/",
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'json' => ['request' => ['action' => $action]],
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            return [
                'success' => $response->getStatusCode() === 200,
                'status_code' => $response->getStatusCode(),
                'body' => $body,
                'data' => $data['body'] ?? $data ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make an API request using the correct GrandStream format.
     *
     * Format: POST /cgi-bin/api.values.get
     * Content-Type: application/x-www-form-urlencoded
     * Body: request=param1:param2:param3&sid=<sid>
     * Cookies: HttpOnly; session-identity=<sid>; session-role=admin
     *
     * @param  string  $ip  Phone IP address
     * @param  array  $parameters  List of parameter names to request
     * @param  PhoneSession  $session  Active phone session
     * @return array Response with 'success', 'data', etc.
     */
    public function getParameters(string $ip, array $parameters, PhoneSession $session): array
    {
        try {
            $client = new Client([
                'timeout' => 15,
                'http_errors' => false,
            ]);

            $cookies = $session->getCookiesArray();
            $sid = $cookies['session-identity'] ?? $session->session_id;

            $cookieString = implode('; ', array_map(
                fn($k, $v) => $v ? "{$k}={$v}" : $k,
                array_keys($cookies),
                $cookies
            ));

            // Build request body in correct format: request=param1:param2:param3&sid=<sid>
            $requestParams = implode(':', $parameters);
            $formBody = "request={$requestParams}&sid={$sid}";

            $response = $client->post("http://{$ip}" . self::ENDPOINT_API_VALUES_GET, [
                'headers' => [
                    'Accept' => '*/*',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => $cookieString,
                    'Origin' => "http://{$ip}",
                    'Pragma' => 'no-cache',
                    'Referer' => "http://{$ip}/",
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                ],
                'body' => $formBody,
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            // Check for session expiration
            if (str_contains($body, 'session-expired')) {
                $session->revoke();
                return [
                    'success' => false,
                    'error' => 'Session expired',
                    'session_expired' => true,
                ];
            }

            $isSuccess = ($data['response'] ?? '') === 'success';

            return [
                'success' => $isSuccess,
                'status_code' => $response->getStatusCode(),
                'body' => $body,
                'data' => $data['body'] ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get device information from the phone.
     *
     * Returns vendor name, model, firmware versions, etc.
     *
     * @param  VoipPhone  $phone  The phone to query
     * @return array Device information or error
     */
    public function getDeviceInfo(VoipPhone $phone): array
    {
        $sessionResult = $this->getOrCreateSession($phone);

        if (!$sessionResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to establish session: ' . ($sessionResult['error'] ?? 'Unknown error'),
            ];
        }

        /** @var PhoneSession $session */
        $session = $sessionResult['session'];

        $result = $this->getParameters($phone->ip, self::DEVICE_INFO_PARAMS, $session);

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data'];

        return [
            'success' => true,
            'device_info' => [
                'vendor' => $data['vendor_name'] ?? null,
                'vendor_fullname' => $data['vendor_fullname'] ?? null,
                'model' => $data['phone_model'] ?? null,
                'core_version' => $data['core_version'] ?? null,
                'base_version' => $data['base_version'] ?? null,
                'boot_version' => $data['boot_version'] ?? null,
                'prog_version' => $data['prog_version'] ?? null,
                'dsp_version' => $data['dsp_version'] ?? null,
            ],
            'raw_data' => $data,
        ];
    }

    /**
     * Get TR069 configuration from the phone.
     *
     * @param  VoipPhone  $phone  The phone to query
     * @return array TR069 configuration or error
     */
    public function getTR069Config(VoipPhone $phone): array
    {
        $sessionResult = $this->getOrCreateSession($phone);

        if (!$sessionResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to establish session',
            ];
        }

        /** @var PhoneSession $session */
        $session = $sessionResult['session'];

        // TR069 P-values: P8020=enable, P8021=ACS URL, P8023=username, etc.
        $tr069Params = ['P8020', 'P8021', 'P8023', 'P8024', 'P8025'];

        $result = $this->getParameters($phone->ip, $tr069Params, $session);

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data'];

        return [
            'success' => true,
            'tr069_config' => [
                'enabled' => ($data['P8020'] ?? '0') === '1',
                'acs_url' => $data['P8021'] ?? null,
                'username' => $data['P8023'] ?? null,
                'periodic_inform_interval' => $data['P8024'] ?? null,
                'connection_request_port' => $data['P8025'] ?? null,
            ],
            'raw_data' => $data,
        ];
    }

    /**
     * Update a VoipPhone model with device information from the phone.
     *
     * @param  VoipPhone  $phone  The phone to update
     * @return array Result with updated phone data or error
     */
    public function syncPhoneInfo(VoipPhone $phone): array
    {
        $deviceInfo = $this->getDeviceInfo($phone);

        if (!$deviceInfo['success']) {
            return $deviceInfo;
        }

        $info = $deviceInfo['device_info'];

        // Update phone model
        $phone->update([
            'vendor' => strtolower($info['vendor'] ?? 'grandstream'),
            'model' => $info['model'],
            'firmware' => $info['prog_version'] ?? $info['core_version'],
            'last_seen' => now(),
            'status' => 'online',
        ]);

        return [
            'success' => true,
            'phone' => $phone->fresh(),
            'device_info' => $info,
        ];
    }
}
