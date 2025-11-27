<?php

namespace Tests\Feature\Api;

use App\Services\JWTService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_username_and_password(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_login_with_invalid_credentials_fails(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'invalid',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_login_with_valid_dev_credentials_succeeds(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'refresh_token',
                'token_type',
                'expires_in',
                'user' => [
                    'id',
                    'name',
                    'email',
                ],
            ])
            ->assertJsonPath('token_type', 'bearer');
    }

    public function test_login_creates_session_tokens(): void
    {
        $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin',
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => 'admin',
            'name' => 'access_token',
        ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => 'admin',
            'name' => 'refresh_token',
        ]);
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/auth/user');

        $response->assertStatus(401);
    }

    public function test_user_endpoint_returns_user_with_valid_token(): void
    {
        // First, login to get a token
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin',
        ]);

        $token = $loginResponse->json('token');

        // Then, use the token to access the user endpoint
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/auth/user');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                ],
            ]);
    }

    public function test_logout_invalidates_token(): void
    {
        // Login
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin',
        ]);

        $token = $loginResponse->json('token');

        // Logout
        $logoutResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/auth/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out successfully',
            ]);
    }

    public function test_refresh_token_requires_refresh_token(): void
    {
        $response = $this->postJson('/api/auth/refresh', []);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Refresh token required',
            ]);
    }

    public function test_refresh_token_with_valid_token_returns_new_tokens(): void
    {
        // Login to get tokens
        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin',
        ]);

        $refreshToken = $loginResponse->json('refresh_token');

        // Refresh token
        $response = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'refresh_token',
                'token_type',
                'expires_in',
            ]);
    }

    public function test_pam_status_returns_status(): void
    {
        $response = $this->getJson('/api/auth/pam-status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'available',
            ]);
    }

    public function test_sessions_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/auth/sessions');

        $response->assertStatus(401);
    }

    public function test_logout_all_invalidates_all_tokens(): void
    {
        // Login twice to create multiple sessions
        $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin',
        ]);

        $loginResponse2 = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin',
        ]);

        $token = $loginResponse2->json('token');

        // Logout from all sessions
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/auth/logout-all');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out from all sessions successfully',
            ]);

        // Verify all tokens are removed
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => 'admin',
        ]);
    }
}
