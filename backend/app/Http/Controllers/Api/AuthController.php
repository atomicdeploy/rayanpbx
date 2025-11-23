<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
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
        
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'username' => ['Too many login attempts. Please try again later.'],
            ]);
        }

        // PAM authentication simulation
        // In production, this would use a PAM library or system call
        $authenticated = $this->authenticateWithPAM(
            $request->username,
            $request->password
        );

        if (!$authenticated) {
            RateLimiter::hit($key, 60);
            
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($key);

        // Create a simple user object (in production, use proper User model)
        $user = (object) [
            'id' => $request->username,
            'name' => $request->username,
            'email' => $request->username . '@local',
        ];

        // Create token
        $token = bin2hex(random_bytes(32));
        
        // Store session (simplified - use proper session management in production)
        cache()->put("session:{$token}", $user, now()->addHours(2));

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $token = $request->bearerToken();
        if ($token) {
            cache()->forget("session:{$token}");
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Get current user
     */
    public function user(Request $request)
    {
        $token = $request->bearerToken();
        $user = cache()->get("session:{$token}");

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return response()->json(['user' => $user]);
    }

    /**
     * Authenticate with PAM
     * This is a simplified simulation. In production, use a proper PAM library.
     */
    private function authenticateWithPAM(string $username, string $password): bool
    {
        // For development/testing purposes
        if (app()->environment('local', 'testing')) {
            // Allow 'admin' with any password in development
            return $username === 'admin';
        }

        // In production, this would call PAM:
        // Example: Use pecl-pam extension or exec pam_auth
        // return pam_auth($username, $password);
        
        return false;
    }
}
