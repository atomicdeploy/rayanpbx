<?php

namespace App\Guards;

use App\Models\SessionToken;
use App\Models\User;
use App\Services\JWTService;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class JWTGuard implements Guard
{
    protected $provider;

    protected $user;

    protected $jwtService;

    protected $request;

    protected $accessToken;

    public function __construct(UserProvider $provider, Request $request, JWTService $jwtService)
    {
        $this->provider = $provider;
        $this->request = $request;
        $this->jwtService = $jwtService;
    }

    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool
    {
        return ! is_null($this->user());
    }

    /**
     * Determine if the current user is a guest.
     */
    public function guest(): bool
    {
        return ! $this->check();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user()
    {
        if (! is_null($this->user)) {
            return $this->user;
        }

        $token = $this->jwtService->extractTokenFromRequest($this->request);

        if (! $token) {
            return null;
        }

        $decoded = $this->jwtService->verifyToken($token);

        if (! $decoded || ! isset($decoded->user)) {
            return null;
        }

        // Validate token against database if JTI is present
        if (isset($decoded->jti)) {
            $sessionToken = SessionToken::findByJti($decoded->jti);

            if (! $sessionToken) {
                // Token has been revoked or doesn't exist in database
                return null;
            }

            if ($sessionToken->isExpired()) {
                // Token has expired
                $sessionToken->revoke();

                return null;
            }

            // Update last used timestamp
            $sessionToken->updateLastUsed();
            $this->accessToken = $sessionToken;
        }

        // Create a User instance from JWT payload
        $userData = (array) $decoded->user;

        // Validate required fields
        if (! isset($userData['id']) || ! isset($userData['name']) || ! isset($userData['email'])) {
            return null;
        }

        // Check cache first - use cached data if available and has same structure
        $cachedUser = cache()->get("user:{$userData['id']}");
        if ($cachedUser && is_array($cachedUser) &&
            isset($cachedUser['id'], $cachedUser['name'], $cachedUser['email'])) {
            $userData = $cachedUser;
        }

        // Create user instance with validated data
        $user = new User;
        $user->id = $userData['id'];
        $user->name = $userData['name'];
        $user->email = $userData['email'];
        $user->exists = true;

        $this->user = $user;

        return $this->user;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id()
    {
        if ($user = $this->user()) {
            return $user->getAuthIdentifier();
        }
    }

    /**
     * Validate a user's credentials.
     *
     * Note: This method is not supported for JWT authentication.
     * JWT tokens are validated in the user() method.
     */
    public function validate(array $credentials = []): bool
    {
        // JWT authentication doesn't support credential validation
        // Token validation happens in the user() method
        throw new \BadMethodCallException('Credential validation is not supported for JWT authentication.');
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return ! is_null($this->user);
    }

    /**
     * Set the current user.
     */
    public function setUser($user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get the current access token.
     */
    public function currentAccessToken(): ?SessionToken
    {
        return $this->accessToken;
    }
}
