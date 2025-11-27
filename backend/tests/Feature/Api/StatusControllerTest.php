<?php

namespace Tests\Feature\Api;

use App\Models\Extension;
use App\Models\Trunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Get a valid auth token for testing.
     */
    private function getAuthToken(): string
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin',
        ]);

        return $response->json('token');
    }

    public function test_status_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/status');

        $response->assertStatus(401);
    }

    public function test_status_endpoint_returns_system_status(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status' => [
                    'asterisk',
                    'database',
                    'extensions' => [
                        'total',
                        'active',
                        'registered',
                    ],
                    'trunks' => [
                        'total',
                        'active',
                        'online',
                    ],
                ],
            ]);
    }

    public function test_status_shows_correct_extension_counts(): void
    {
        // Create some extensions
        Extension::factory()->count(3)->create(['enabled' => true]);
        Extension::factory()->count(2)->disabled()->create();

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/status');

        $response->assertStatus(200)
            ->assertJsonPath('status.extensions.total', 5)
            ->assertJsonPath('status.extensions.active', 3);
    }

    public function test_status_shows_correct_trunk_counts(): void
    {
        // Create some trunks
        Trunk::factory()->count(2)->create(['enabled' => true]);
        Trunk::factory()->disabled()->create();

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/status');

        $response->assertStatus(200)
            ->assertJsonPath('status.trunks.total', 3)
            ->assertJsonPath('status.trunks.active', 2);
    }

    public function test_extensions_status_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/status/extensions');

        $response->assertStatus(401);
    }

    public function test_extensions_status_returns_enabled_extensions(): void
    {
        Extension::factory()->create([
            'extension_number' => '101',
            'name' => 'Test User 1',
            'enabled' => true,
        ]);
        Extension::factory()->create([
            'extension_number' => '102',
            'name' => 'Test User 2',
            'enabled' => true,
        ]);
        Extension::factory()->disabled()->create();

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/status/extensions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'extensions' => [
                    '*' => [
                        'extension',
                        'name',
                        'status',
                        'ip',
                    ],
                ],
            ])
            ->assertJsonCount(2, 'extensions');
    }

    public function test_trunks_status_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/status/trunks');

        $response->assertStatus(401);
    }

    public function test_trunks_status_returns_enabled_trunks(): void
    {
        Trunk::factory()->create([
            'name' => 'trunk1',
            'host' => '192.168.1.100',
            'enabled' => true,
        ]);
        Trunk::factory()->create([
            'name' => 'trunk2',
            'host' => '192.168.1.101',
            'enabled' => true,
        ]);
        Trunk::factory()->disabled()->create();

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/status/trunks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'trunks' => [
                    '*' => [
                        'name',
                        'host',
                        'status',
                        'latency',
                    ],
                ],
            ])
            ->assertJsonCount(2, 'trunks');
    }

    public function test_status_shows_database_connected(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/status');

        $response->assertStatus(200)
            ->assertJsonPath('status.database', 'connected');
    }
}
