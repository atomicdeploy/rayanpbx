<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SessionToken;
use App\Services\JWTService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private JWTService $jwtService;

    public function __construct(JWTService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    /**
     * Login with PAM authentication
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $key = 'login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) env('RATE_LIMIT_LOGIN', 5))) {
            throw ValidationException::withMessages([
                'username' => ['Too many login attempts. Please try again later.'],
            ]);
        }

        // PAM authentication
        $authenticated = $this->authenticateWithPAM(
            $request->username,
            $request->password
        );

        if (! $authenticated) {
            RateLimiter::hit($key, (int) env('RATE_LIMIT_LOGIN_DECAY', 60));

            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($key);

        // Create user payload
        $user = [
            'id' => $request->username,
            'name' => $request->username,
            'email' => $request->username.'@local',
        ];

        // Generate unique JTI for access and refresh tokens
        $accessJti = $this->jwtService->generateJti();
        $refreshJti = $this->jwtService->generateJti();

        // Generate JWT tokens with JTI
        $token = $this->jwtService->generateToken(['user' => $user], $accessJti);
        $refreshToken = $this->jwtService->generateRefreshToken(['user' => $user], $refreshJti);

        // Store access token in database
        SessionToken::create([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $request->username,
            'name' => 'access_token',
            'token' => hash('sha256', $token),
            'jti' => $accessJti,
            'abilities' => ['*'],
            'expires_at' => now()->addSeconds($this->jwtService->getExpiration()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Store refresh token in database
        SessionToken::create([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $request->username,
            'name' => 'refresh_token',
            'token' => hash('sha256', $refreshToken),
            'jti' => $refreshJti,
            'abilities' => ['refresh'],
            'expires_at' => now()->addSeconds($this->jwtService->getRefreshExpiration()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Store in cache for quick lookup
        cache()->put("user:{$request->username}", $user, now()->addHours(2));

        $response = response()->json([
            'token' => $token,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => $this->jwtService->getExpiration(),
            'user' => $user,
        ]);

        // Set cookie
        return $response->cookie(
            'rayanpbx_token',
            $token,
            $this->jwtService->getExpiration() / 60,
            '/',
            null,
            env('SESSION_SECURE_COOKIE', false),
            true
        );
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request)
    {
        $refreshToken = $request->input('refresh_token');

        if (! $refreshToken) {
            return response()->json(['message' => 'Refresh token required'], 400);
        }

        $decoded = $this->jwtService->verifyToken($refreshToken);

        if (! $decoded || ! isset($decoded->type) || $decoded->type !== 'refresh') {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        // Validate refresh token exists in database
        if (isset($decoded->jti)) {
            $sessionToken = SessionToken::findByJti($decoded->jti);

            if (! $sessionToken) {
                return response()->json(['message' => 'Refresh token has been revoked'], 401);
            }

            if ($sessionToken->isExpired()) {
                $sessionToken->revoke();

                return response()->json(['message' => 'Refresh token has expired'], 401);
            }

            // Revoke old refresh token
            $sessionToken->revoke();
        }

        // Generate new tokens with new JTIs
        $accessJti = $this->jwtService->generateJti();
        $refreshJti = $this->jwtService->generateJti();

        $token = $this->jwtService->generateToken(['user' => $decoded->user], $accessJti);
        $newRefreshToken = $this->jwtService->generateRefreshToken(['user' => $decoded->user], $refreshJti);

        $userId = $decoded->user->id ?? 'unknown';

        // Store new access token in database
        SessionToken::create([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $userId,
            'name' => 'access_token',
            'token' => hash('sha256', $token),
            'jti' => $accessJti,
            'abilities' => ['*'],
            'expires_at' => now()->addSeconds($this->jwtService->getExpiration()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Store new refresh token in database
        SessionToken::create([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => $userId,
            'name' => 'refresh_token',
            'token' => hash('sha256', $newRefreshToken),
            'jti' => $refreshJti,
            'abilities' => ['refresh'],
            'expires_at' => now()->addSeconds($this->jwtService->getRefreshExpiration()),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'token' => $token,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'bearer',
            'expires_in' => $this->jwtService->getExpiration(),
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $token = $this->jwtService->extractTokenFromRequest($request);

        if ($token) {
            $decoded = $this->jwtService->verifyToken($token);
            if ($decoded) {
                // Revoke token from database if JTI exists
                if (isset($decoded->jti)) {
                    $sessionToken = SessionToken::findByJti($decoded->jti);
                    if ($sessionToken) {
                        $sessionToken->revoke();
                    }
                }

                if (isset($decoded->user->id)) {
                    cache()->forget("user:{$decoded->user->id}");
                }
            }
        }

        return response()->json(['message' => 'Logged out successfully'])
            ->cookie('rayanpbx_token', '', -1);
    }

    /**
     * Logout from all sessions
     */
    public function logoutAll(Request $request)
    {
        $token = $this->jwtService->extractTokenFromRequest($request);

        if ($token) {
            $decoded = $this->jwtService->verifyToken($token);
            if ($decoded && isset($decoded->user->id)) {
                // Revoke all tokens for this user
                SessionToken::where('tokenable_type', 'App\\Models\\User')
                    ->where('tokenable_id', $decoded->user->id)
                    ->delete();

                cache()->forget("user:{$decoded->user->id}");
            }
        }

        return response()->json(['message' => 'Logged out from all sessions successfully'])
            ->cookie('rayanpbx_token', '', -1);
    }

    /**
     * Get current user
     */
    public function user(Request $request)
    {
        $token = $this->jwtService->extractTokenFromRequest($request);

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $decoded = $this->jwtService->verifyToken($token);

        if (! $decoded || ! isset($decoded->user)) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        return response()->json(['user' => $decoded->user]);
    }

    /**
     * List active sessions/tokens
     */
    public function sessions(Request $request)
    {
        $token = $this->jwtService->extractTokenFromRequest($request);

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $decoded = $this->jwtService->verifyToken($token);

        if (! $decoded || ! isset($decoded->user->id)) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $sessions = SessionToken::where('tokenable_type', 'App\\Models\\User')
            ->where('tokenable_id', $decoded->user->id)
            ->where('name', 'access_token')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get(['id', 'name', 'ip_address', 'user_agent', 'last_used_at', 'created_at', 'expires_at']);

        // Mark current session
        $currentJti = $decoded->jti ?? null;
        $sessions = $sessions->map(function ($session) use ($currentJti) {
            $sessionToken = SessionToken::find($session->id);

            return [
                'id' => $session->id,
                'name' => $session->name,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_used_at' => $session->last_used_at,
                'created_at' => $session->created_at,
                'expires_at' => $session->expires_at,
                'is_current' => $sessionToken && $sessionToken->jti === $currentJti,
            ];
        });

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * Revoke a specific session/token
     */
    public function revokeSession(Request $request, $sessionId)
    {
        $token = $this->jwtService->extractTokenFromRequest($request);

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $decoded = $this->jwtService->verifyToken($token);

        if (! $decoded || ! isset($decoded->user->id)) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $session = SessionToken::where('id', $sessionId)
            ->where('tokenable_type', 'App\\Models\\User')
            ->where('tokenable_id', $decoded->user->id)
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found'], 404);
        }

        $session->revoke();

        return response()->json(['message' => 'Session revoked successfully']);
    }

    /**
     * Authenticate with PAM
     */
    private function authenticateWithPAM(string $username, string $password): bool
    {
        // For development/testing purposes
        if (app()->environment('local', 'testing', 'development')) {
            // Allow 'admin' with password 'admin' in development
            return $username === 'admin' && $password === 'admin';
        }

        if (! env('RAYANPBX_PAM_ENABLED', true)) {
            return false;
        }

        // In production, use PAM authentication
        // Option 1: Using pam_auth if pecl-pam is installed
        if (function_exists('pam_auth')) {
            return pam_auth($username, $password, $error, false);
        }

        // Option 2: Using exec with pamtester
        $command = sprintf(
            'pamtester -v rayanpbx %s authenticate 2>&1',
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

        if (is_resource($process)) {
            fwrite($pipes[0], $password);
            fclose($pipes[0]);

            stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);

            return $returnCode === 0;
        }

        return false;
    }
}
