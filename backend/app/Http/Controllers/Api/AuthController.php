<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $key = 'login:' . $request->ip();
        
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

        if (!$authenticated) {
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
            'email' => $request->username . '@local',
        ];

        // Generate JWT tokens
        $token = $this->jwtService->generateToken(['user' => $user]);
        $refreshToken = $this->jwtService->generateRefreshToken(['user' => $user]);

        // Store in cache for quick lookup
        cache()->put("user:{$request->username}", $user, now()->addHours(2));

        $response = response()->json([
            'token' => $token,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => (int) env('JWT_EXPIRATION', 7200),
            'user' => $user,
        ]);

        // Set cookie
        return $response->cookie(
            'rayanpbx_token',
            $token,
            (int) env('JWT_EXPIRATION', 7200) / 60,
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
        
        if (!$refreshToken) {
            return response()->json(['message' => 'Refresh token required'], 400);
        }

        $decoded = $this->jwtService->verifyToken($refreshToken);
        
        if (!$decoded || !isset($decoded->type) || $decoded->type !== 'refresh') {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        // Generate new tokens
        $token = $this->jwtService->generateToken(['user' => $decoded->user]);
        $newRefreshToken = $this->jwtService->generateRefreshToken(['user' => $decoded->user]);

        return response()->json([
            'token' => $token,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'bearer',
            'expires_in' => (int) env('JWT_EXPIRATION', 7200),
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
            if ($decoded && isset($decoded->user->id)) {
                cache()->forget("user:{$decoded->user->id}");
            }
        }

        return response()->json(['message' => 'Logged out successfully'])
            ->cookie('rayanpbx_token', '', -1);
    }

    /**
     * Get current user
     */
    public function user(Request $request)
    {
        $token = $this->jwtService->extractTokenFromRequest($request);
        
        if (!$token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $decoded = $this->jwtService->verifyToken($token);
        
        if (!$decoded || !isset($decoded->user)) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        return response()->json(['user' => $decoded->user]);
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

        if (!env('RAYANPBX_PAM_ENABLED', true)) {
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
