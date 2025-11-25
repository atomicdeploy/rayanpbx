<?php

namespace App\Services;

/**
 * PAM Authentication Service
 *
 * Provides Linux PAM (Pluggable Authentication Modules) authentication
 * for authenticating users against the system's user database.
 */
class PamAuthService
{
    private SystemLogService $logger;

    public function __construct(?SystemLogService $logger = null)
    {
        $this->logger = $logger ?? new SystemLogService();
    }

    /**
     * Authenticate a user using PAM
     *
     * @param string $username The username to authenticate
     * @param string $password The password to verify
     * @return bool True if authentication succeeded, false otherwise
     */
    public function authenticate(string $username, string $password): bool
    {
        // Validate inputs
        if (empty($username) || empty($password)) {
            $this->logger->authWarning('PAM authentication attempted with empty credentials');
            return false;
        }

        // Sanitize username to prevent injection attacks
        if (!$this->isValidUsername($username)) {
            $this->logger->authWarning("PAM authentication rejected - invalid username format: {$username}");
            return false;
        }

        // Try PAM authentication methods in order of preference
        $result = $this->tryPamAuth($username, $password)
            ?? $this->tryPamtester($username, $password)
            ?? $this->tryShadowAuth($username, $password);

        if ($result === true) {
            $this->logger->authInfo("PAM authentication successful for user: {$username}");
            return true;
        }

        $this->logger->authWarning("PAM authentication failed for user: {$username}");
        return false;
    }

    /**
     * Validate username format to prevent injection attacks
     */
    private function isValidUsername(string $username): bool
    {
        // Linux usernames: alphanumeric, underscore, hyphen, starts with letter or underscore
        // Maximum length 32 characters
        return preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $username) === 1;
    }

    /**
     * Try PHP PAM extension (pam_auth function)
     */
    private function tryPamAuth(string $username, string $password): ?bool
    {
        if (!function_exists('pam_auth')) {
            return null;
        }

        $error = '';
        try {
            $result = pam_auth($username, $password, $error, false);
            if (!$result && !empty($error)) {
                $this->logger->authDebug("pam_auth error: {$error}");
            }
            return $result;
        } catch (\Throwable $e) {
            $this->logger->authDebug("pam_auth exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Try pamtester command
     */
    private function tryPamtester(string $username, string $password): ?bool
    {
        $pamtesterPath = $this->findPamtester();
        if ($pamtesterPath === null) {
            return null;
        }

        // Get PAM service name from config or use default
        $pamService = env('RAYANPBX_PAM_SERVICE', 'rayanpbx');

        // Check if our PAM service exists, fallback to 'login' or 'other'
        $pamServicePath = "/etc/pam.d/{$pamService}";
        if (!file_exists($pamServicePath)) {
            // Try common fallback services
            foreach (['login', 'system-auth', 'other'] as $fallback) {
                if (file_exists("/etc/pam.d/{$fallback}")) {
                    $pamService = $fallback;
                    break;
                }
            }
        }

        $command = sprintf(
            '%s -v %s %s authenticate 2>&1',
            escapeshellcmd($pamtesterPath),
            escapeshellarg($pamService),
            escapeshellarg($username)
        );

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ],
            $pipes
        );

        if (!is_resource($process)) {
            $this->logger->authDebug("Failed to open pamtester process");
            return null;
        }

        // Write password to stdin
        fwrite($pipes[0], $password);
        fclose($pipes[0]);

        // Read output
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            $this->logger->authDebug("pamtester failed with code {$returnCode}: {$stderr}");
        }

        return $returnCode === 0;
    }

    /**
     * Find pamtester binary
     */
    private function findPamtester(): ?string
    {
        $paths = [
            '/usr/bin/pamtester',
            '/usr/local/bin/pamtester',
            '/bin/pamtester',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Try shadow password file authentication (requires read access to /etc/shadow)
     * This is a fallback when PAM tools are not available
     */
    private function tryShadowAuth(string $username, string $password): ?bool
    {
        // Check if we can read shadow file (requires root or shadow group membership)
        if (!is_readable('/etc/shadow')) {
            $this->logger->authDebug("Cannot read /etc/shadow - insufficient permissions");
            return null;
        }

        $shadowContent = @file_get_contents('/etc/shadow');
        if ($shadowContent === false) {
            return null;
        }

        $lines = explode("\n", $shadowContent);
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) < 2) {
                continue;
            }

            $shadowUsername = $parts[0];
            $shadowHash = $parts[1];

            if ($shadowUsername !== $username) {
                continue;
            }

            // Empty or locked password
            if (empty($shadowHash) || $shadowHash === '!' || $shadowHash === '*' || $shadowHash === '!!') {
                $this->logger->authDebug("User {$username} has no password or account is locked");
                return false;
            }

            // Validate hash format - should start with $id$ for modern crypt algorithms
            // Supported formats: $1$ (MD5), $5$ (SHA-256), $6$ (SHA-512), $y$ (yescrypt)
            if (!preg_match('/^\$[156y]\$/', $shadowHash)) {
                // May be an older DES-based hash or unknown format
                $this->logger->authDebug("User {$username} has unsupported hash format");
            }

            // Verify password using crypt with the stored hash
            $computedHash = crypt($password, $shadowHash);
            
            // crypt() returns '*0' or '*1' on failure
            if ($computedHash[0] === '*') {
                $this->logger->authDebug("Password verification failed - invalid crypt result");
                return false;
            }
            
            return hash_equals($shadowHash, $computedHash);
        }

        $this->logger->authDebug("User {$username} not found in shadow file");
        return false;
    }

    /**
     * Check if PAM authentication is properly configured and available
     */
    public function isAvailable(): array
    {
        $status = [
            'available' => false,
            'methods' => [],
            'recommended_setup' => null,
        ];

        // Check PHP PAM extension
        if (function_exists('pam_auth')) {
            $status['methods']['pam_extension'] = true;
            $status['available'] = true;
        }

        // Check pamtester
        if ($this->findPamtester() !== null) {
            $status['methods']['pamtester'] = true;
            $status['available'] = true;

            // Check if rayanpbx PAM service exists
            $status['methods']['rayanpbx_pam_service'] = file_exists('/etc/pam.d/rayanpbx');
        }

        // Check shadow file access
        if (is_readable('/etc/shadow')) {
            $status['methods']['shadow_auth'] = true;
            $status['available'] = true;
        }

        if (!$status['available']) {
            // Get the base path dynamically
            $basePath = env('RAYANPBX_PATH', '/opt/rayanpbx');
            $status['recommended_setup'] = "Run the PAM setup script: sudo {$basePath}/scripts/setup-pam.sh";
        }

        return $status;
    }
}
