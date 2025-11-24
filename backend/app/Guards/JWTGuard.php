<?php

namespace App\Guards;

use App\Services\JWTService;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use App\Models\User;

class JWTGuard implements Guard
{
    protected $provider;
    protected $user;
    protected $jwtService;
    protected $request;

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
        return !is_null($this->user());
    }

    /**
     * Determine if the current user is a guest.
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user()
    {
        if (!is_null($this->user)) {
            return $this->user;
        }

        $token = $this->jwtService->extractTokenFromRequest($this->request);

        if (!$token) {
            return null;
        }

        $decoded = $this->jwtService->verifyToken($token);

        if (!$decoded || !isset($decoded->user)) {
            return null;
        }

        // Create a User instance from JWT payload
        $userData = (array) $decoded->user;
        
        // Validate required fields
        if (!isset($userData['id']) || !isset($userData['name']) || !isset($userData['email'])) {
            return null;
        }
        
        // Check cache first
        $cachedUser = cache()->get("user:{$userData['id']}");
        if ($cachedUser) {
            $userData = $cachedUser;
        }

        $user = new User();
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
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return !is_null($this->user);
    }

    /**
     * Set the current user.
     */
    public function setUser($user)
    {
        $this->user = $user;
        return $this;
    }
}
