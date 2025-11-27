<?php

namespace App\Services;

use App\Models\PhoneSession;
use App\Models\VoipPhone;
use Illuminate\Support\Facades\Log;

/**
 * GrandStream Session Authentication Service
 *
 * Provides session-based authentication for GrandStream phones.
 * This internal service handles the /cgi-bin/dologin endpoint functionality
 * to establish and maintain persistent sessions instead of including
 * credentials in every request.
 *
 * Note: GrandStream phones return HTTP/1.0 responses with malformed headers
 * (e.g., "Set-Cookie: HttpOnly" without a value) which are not compatible
 * with Guzzle/cURL. This service uses PHP's native stream functions for
 * maximum compatibility.
 *
 * Based on GrandStream GXP series (tested with GXP1625).
 */
class GrandStreamSessionService
{
    protected HttpClientService $httpClient;

    protected const DEFAULT_SESSION_TTL = 1800; // 30 minutes

    protected const ENDPOINT_DOLOGIN = '/cgi-bin/dologin';

    protected const ENDPOINT_API_VALUES_GET = '/cgi-bin/api.values.get';

    protected const ENDPOINT_API_VALUES_POST = '/cgi-bin/api.values.post';

    protected const DEVICE_INFO_PARAMS = [
        'vendor_name', 'vendor_fullname', 'phone_model',
        'core_version', 'base_version', 'boot_version', 'prog_version', 'dsp_version',
    ];

    protected const SIP_ACCOUNT_PARAMS = [
        'account_active' => 'P271',
        'account_name' => 'P270',
        'sip_server' => 'P47',
        'secondary_sip_server' => 'P2312',
        'outbound_proxy' => 'P48',
        'backup_outbound_proxy' => 'P2333',
        'blf_server' => 'P2375',
        'sip_user_id' => 'P35',
        'auth_id' => 'P36',
        'auth_password' => 'P34',
        'display_name' => 'P3',
        'voicemail' => 'P33',
        'account_display' => 'P2380',
    ];

    public function __construct(?HttpClientService $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClientService;
    }

    /**
     * Check if a phone is a temporary/transient object (not persisted to DB).
     */
    protected function isTemporaryPhone(VoipPhone $phone): bool
    {
        return $phone->id <= 0 || ! $phone->exists;
    }

    /**
     * Perform login to a GrandStream phone and store the session.
     * For temporary phones (id <= 0), session is not stored in the database.
     */
    public function login(VoipPhone $phone, ?array $credentials = null): array
    {
        $credentials = $credentials ?? $phone->getCredentialsForApi();
        $username = $credentials['username'] ?? 'admin';
        $password = $credentials['password'] ?? '';

        if (empty($password)) {
            return ['success' => false, 'error' => 'No password provided for authentication'];
        }

        // Only revoke existing sessions for persisted phones
        if (! $this->isTemporaryPhone($phone)) {
            PhoneSession::revokeAllForPhone($phone->id);
        }

        $result = $this->performDologin($phone->ip, $username, $password);

        if ($result['success']) {
            // For temporary phones, return a transient session without DB storage
            if ($this->isTemporaryPhone($phone)) {
                return [
                    'success' => true,
                    'session_id' => null,
                    'sid' => $result['sid'],
                    'role' => $result['role'],
                    'cookies' => $result['cookies'] ?? [],
                    'expires_at' => now()->addSeconds(self::DEFAULT_SESSION_TTL)->toIso8601String(),
                    'message' => 'Successfully authenticated with phone (transient session)',
                    'transient' => true,
                ];
            }

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

        Log::warning('GrandStream login failed', ['phone_ip' => $phone->ip, 'error' => $result['error'] ?? 'Unknown error']);

        return $result;
    }

    /**
     * Build base URL for a phone IP, supporting both http and https.
     * If IP already includes a scheme, use it; otherwise default to http.
     */
    protected function buildBaseUrl(string $ip): string
    {
        if (str_starts_with($ip, 'http://') || str_starts_with($ip, 'https://')) {
            return rtrim($ip, '/');
        }

        return 'http://'.$ip;
    }

    /**
     * Perform authentication via /cgi-bin/dologin endpoint.
     */
    protected function performDologin(string $ip, string $username, string $password): array
    {
        try {
            $baseUrl = $this->buildBaseUrl($ip);

            $result = $this->httpClient->nativePost(
                $baseUrl.self::ENDPOINT_DOLOGIN,
                http_build_query(['username' => $username, 'password' => $password]),
                [
                    'Cookie' => 'HttpOnly',
                    'Referer' => $baseUrl.'/',
                ],
                15
            );

            if (! $result['success']) {
                return $result;
            }

            $body = $result['body'];

            if (str_contains($body, 'Forbidden')) {
                return ['success' => false, 'error' => 'Login forbidden - invalid credentials', 'body' => $body];
            }

            $data = json_decode($body, true);
            if (! $data || ($data['response'] ?? '') !== 'success') {
                return ['success' => false, 'error' => 'Login response was not successful', 'body' => $body];
            }

            $sid = $data['body']['sid'] ?? null;
            $role = $data['body']['role'] ?? 'admin';

            if (! $sid) {
                return ['success' => false, 'error' => 'No session ID received'];
            }

            return [
                'success' => true,
                'method' => 'dologin',
                'sid' => $sid,
                'role' => $role,
                'cookies' => ['HttpOnly' => '', 'session-identity' => $sid, 'session-role' => $role],
            ];
        } catch (\Exception $e) {
            Log::error('GrandStream dologin exception', ['ip' => $ip, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Exception during login: '.$e->getMessage()];
        }
    }

    protected function storeSession(VoipPhone $phone, array $authResult): PhoneSession
    {
        $session = PhoneSession::create([
            'voip_phone_id' => $phone->id,
            'session_id' => $authResult['sid'] ?? null,
            'challenge' => null,
            'token' => $authResult['role'] ?? null,
            'is_active' => true,
            'authenticated_at' => now(),
            'expires_at' => now()->addSeconds(self::DEFAULT_SESSION_TTL),
            'last_used_at' => now(),
        ]);

        if (! empty($authResult['cookies'])) {
            $session->setCookiesFromArray($authResult['cookies']);
            $session->save();
        }

        return $session;
    }

    public function getSession(VoipPhone $phone): ?PhoneSession
    {
        // Temporary phones don't have stored sessions
        if ($this->isTemporaryPhone($phone)) {
            return null;
        }

        $session = PhoneSession::getValidSession($phone->id);
        if ($session) {
            $session->markUsed();

            return $session;
        }

        return null;
    }

    public function getOrCreateSession(VoipPhone $phone, ?array $credentials = null): array
    {
        // For temporary phones, skip session lookup and always login
        if (! $this->isTemporaryPhone($phone)) {
            $session = $this->getSession($phone);
            if ($session) {
                return ['success' => true, 'session' => $session, 'reused' => true];
            }
        }

        $loginResult = $this->login($phone, $credentials);
        if ($loginResult['success']) {
            // For transient sessions, create an in-memory PhoneSession object
            if (! empty($loginResult['transient'])) {
                $session = new PhoneSession([
                    'voip_phone_id' => 0,
                    'session_id' => $loginResult['sid'],
                    'token' => $loginResult['role'],
                    'is_active' => true,
                    'authenticated_at' => now(),
                    'expires_at' => now()->addSeconds(self::DEFAULT_SESSION_TTL),
                    'last_used_at' => now(),
                ]);
                if (! empty($loginResult['cookies'])) {
                    $session->setCookiesFromArray($loginResult['cookies']);
                }

                return ['success' => true, 'session' => $session, 'reused' => false, 'transient' => true];
            }

            $session = PhoneSession::find($loginResult['session_id']);

            return ['success' => true, 'session' => $session, 'reused' => false];
        }

        return ['success' => false, 'error' => $loginResult['error'] ?? 'Failed to create session'];
    }

    public function logout(VoipPhone $phone): bool
    {
        // Temporary phones don't have stored sessions to revoke
        if ($this->isTemporaryPhone($phone)) {
            return true;
        }

        return PhoneSession::revokeAllForPhone($phone->id) > 0;
    }

    protected function buildCookieHeader(PhoneSession $session): string
    {
        $cookies = $session->getCookiesArray();
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = $value ? "{$name}={$value}" : $name;
        }

        return implode('; ', $parts);
    }

    /**
     * Get parameters from the phone.
     * Body format: request=param1:param2:param3&sid=<sid>
     */
    public function getParameters(string $ip, array $parameters, PhoneSession $session): array
    {
        try {
            $cookies = $session->getCookiesArray();
            $sid = $cookies['session-identity'] ?? $session->session_id;
            $baseUrl = $this->buildBaseUrl($ip);

            // Note: Origin header is not required for API requests.
            // Only the Cookie header with session-identity is needed.
            $result = $this->httpClient->nativePost(
                $baseUrl.self::ENDPOINT_API_VALUES_GET,
                'request='.implode(':', $parameters).'&sid='.$sid,
                [
                    'Cookie' => $this->buildCookieHeader($session),
                ],
                15
            );

            if (! $result['success']) {
                return $result;
            }

            $body = $result['body'];
            if (str_contains($body, 'session-expired')) {
                $session->revoke();

                return ['success' => false, 'error' => 'Session expired', 'session_expired' => true];
            }

            $data = json_decode($body, true);
            $isSuccess = ($data['response'] ?? '') === 'success';

            return [
                'success' => $isSuccess,
                'body' => $body,
                'data' => $data['body'] ?? [],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Set parameters on the phone.
     * Body format: P270=value1&P47=value2&sid=<sid>
     */
    public function setParameters(string $ip, array $parameters, PhoneSession $session): array
    {
        try {
            $cookies = $session->getCookiesArray();
            $sid = $cookies['session-identity'] ?? $session->session_id;
            $baseUrl = $this->buildBaseUrl($ip);

            $formParts = [];
            foreach ($parameters as $name => $value) {
                $formParts[] = urlencode($name).'='.urlencode((string) $value);
            }
            $formParts[] = 'sid='.urlencode($sid);

            $result = $this->httpClient->nativePost(
                $baseUrl.self::ENDPOINT_API_VALUES_POST,
                implode('&', $formParts),
                [
                    'Cookie' => $this->buildCookieHeader($session),
                ],
                15
            );

            if (! $result['success']) {
                return $result;
            }

            $body = $result['body'];
            if (str_contains($body, 'session-expired')) {
                $session->revoke();

                return ['success' => false, 'error' => 'Session expired', 'session_expired' => true];
            }

            $data = json_decode($body, true);
            $isSuccess = ($data['response'] ?? '') === 'success';
            $status = $data['body']['status'] ?? null;

            return [
                'success' => $isSuccess && $status === 'right',
                'body' => $body,
                'status' => $status,
                'data' => $data['body'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::error('GrandStream setParameters failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getDeviceInfo(VoipPhone $phone, ?array $credentials = null): array
    {
        $sessionResult = $this->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            return ['success' => false, 'error' => 'Failed to establish session: '.($sessionResult['error'] ?? 'Unknown error')];
        }

        $result = $this->getParameters($phone->ip, self::DEVICE_INFO_PARAMS, $sessionResult['session']);
        if (! $result['success']) {
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

    public function getTR069Config(VoipPhone $phone, ?array $credentials = null): array
    {
        $sessionResult = $this->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            return ['success' => false, 'error' => 'Failed to establish session'];
        }

        $tr069Params = ['P8020', 'P8021', 'P8023', 'P8024', 'P8025'];
        $result = $this->getParameters($phone->ip, $tr069Params, $sessionResult['session']);
        if (! $result['success']) {
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

    public function syncPhoneInfo(VoipPhone $phone, ?array $credentials = null): array
    {
        $deviceInfo = $this->getDeviceInfo($phone, $credentials);
        if (! $deviceInfo['success']) {
            return $deviceInfo;
        }

        $info = $deviceInfo['device_info'];
        $phone->update([
            'vendor' => strtolower($info['vendor'] ?? 'grandstream'),
            'model' => $info['model'],
            'firmware' => $info['prog_version'] ?? $info['core_version'],
            'last_seen' => now(),
            'status' => 'online',
        ]);

        return ['success' => true, 'phone' => $phone->fresh(), 'device_info' => $info];
    }

    public function getSipAccount(VoipPhone $phone, int $accountNumber = 1, ?array $credentials = null): array
    {
        $sessionResult = $this->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            return ['success' => false, 'error' => 'Failed to establish session: '.($sessionResult['error'] ?? 'Unknown')];
        }

        $result = $this->getParameters($phone->ip, array_values(self::SIP_ACCOUNT_PARAMS), $sessionResult['session']);
        if (! $result['success']) {
            return $result;
        }

        $data = $result['data'];

        return [
            'success' => true,
            'account_number' => $accountNumber,
            'sip_account' => [
                'account_active' => ($data['P271'] ?? '0') === '1',
                'account_name' => $data['P270'] ?? '',
                'sip_server' => $data['P47'] ?? '',
                'secondary_sip_server' => $data['P2312'] ?? '',
                'outbound_proxy' => $data['P48'] ?? '',
                'backup_outbound_proxy' => $data['P2333'] ?? '',
                'blf_server' => $data['P2375'] ?? '',
                'sip_user_id' => $data['P35'] ?? '',
                'auth_id' => $data['P36'] ?? '',
                'display_name' => $data['P3'] ?? '',
                'voicemail' => $data['P33'] ?? '',
                'account_display' => $data['P2380'] ?? '0',
            ],
            'raw_data' => $data,
        ];
    }

    public function setSipAccount(VoipPhone $phone, array $config, int $accountNumber = 1, ?array $credentials = null): array
    {
        $sessionResult = $this->getOrCreateSession($phone, $credentials);
        if (! $sessionResult['success']) {
            return ['success' => false, 'error' => 'Failed to establish session: '.($sessionResult['error'] ?? 'Unknown')];
        }

        $configMapping = [
            'account_active' => 'P271', 'account_name' => 'P270', 'sip_server' => 'P47',
            'secondary_sip_server' => 'P2312', 'outbound_proxy' => 'P48', 'backup_outbound_proxy' => 'P2333',
            'blf_server' => 'P2375', 'sip_user_id' => 'P35', 'auth_id' => 'P36',
            'auth_password' => 'P34', 'display_name' => 'P3', 'voicemail' => 'P33', 'account_display' => 'P2380',
        ];

        $params = [];
        foreach ($configMapping as $configKey => $pValue) {
            if (isset($config[$configKey])) {
                $value = $config[$configKey];
                if ($configKey === 'account_active') {
                    $value = $value ? '1' : '0';
                }
                $params[$pValue] = $value;
            }
        }

        if (empty($params)) {
            return ['success' => false, 'error' => 'No valid configuration parameters provided'];
        }

        $result = $this->setParameters($phone->ip, $params, $sessionResult['session']);
        if (! $result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => 'SIP account configured successfully',
            'account_number' => $accountNumber,
            'parameters_set' => array_keys($params),
        ];
    }

    public function provisionExtension(
        VoipPhone $phone, string $extension, string $password, string $server,
        ?string $displayName = null, int $accountNumber = 1, ?array $credentials = null
    ): array {
        return $this->setSipAccount($phone, [
            'account_active' => true, 'account_name' => 'SIP', 'sip_server' => $server,
            'sip_user_id' => $extension, 'auth_id' => $extension, 'auth_password' => $password,
            'display_name' => $displayName ?? "Extension {$extension}",
        ], $accountNumber, $credentials);
    }

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

    public function cleanupExpiredSessions(): int
    {
        return PhoneSession::cleanupExpired();
    }
}
