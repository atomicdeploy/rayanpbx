<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class JWTService
{
    private string $secret;
    private string $algorithm;
    private int $expiration;
    private int $refreshExpiration;

    public function __construct()
    {
        $this->secret = env('JWT_SECRET', 'your-super-secret-jwt-key-change-this');
        $this->algorithm = env('JWT_ALGORITHM', 'HS256');
        $this->expiration = (int) env('JWT_EXPIRATION', 7200); // 2 hours
        $this->refreshExpiration = (int) env('JWT_REFRESH_EXPIRATION', 604800); // 7 days
    }

    /**
     * Generate JWT token
     */
    public function generateToken(array $payload): string
    {
        $issuedAt = time();
        $expire = $issuedAt + $this->expiration;

        $data = array_merge([
            'iat' => $issuedAt,
            'exp' => $expire,
            'iss' => env('APP_URL', 'http://localhost'),
        ], $payload);

        return JWT::encode($data, $this->secret, $this->algorithm);
    }

    /**
     * Generate refresh token
     */
    public function generateRefreshToken(array $payload): string
    {
        $issuedAt = time();
        $expire = $issuedAt + $this->refreshExpiration;

        $data = array_merge([
            'iat' => $issuedAt,
            'exp' => $expire,
            'iss' => env('APP_URL', 'http://localhost'),
            'type' => 'refresh',
        ], $payload);

        return JWT::encode($data, $this->secret, $this->algorithm);
    }

    /**
     * Decode and verify JWT token
     */
    public function verifyToken(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secret, $this->algorithm));
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Extract token from request
     * Checks in order: GET param, POST param, Bearer header, Cookie
     */
    public function extractTokenFromRequest($request): ?string
    {
        // Check GET parameter
        if ($request->has('token')) {
            return $request->input('token');
        }

        // Check POST parameter
        if ($request->isMethod('post') && $request->has('token')) {
            return $request->input('token');
        }

        // Check Bearer header
        $header = $request->header('Authorization');
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }

        // Check Cookie
        if ($request->hasCookie('rayanpbx_token')) {
            return $request->cookie('rayanpbx_token');
        }

        return null;
    }

    /**
     * Create token response with cookie
     */
    public function createTokenResponse(string $token, string $refreshToken = null): array
    {
        $response = [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $this->expiration,
        ];

        if ($refreshToken) {
            $response['refresh_token'] = $refreshToken;
        }

        return $response;
    }
}
