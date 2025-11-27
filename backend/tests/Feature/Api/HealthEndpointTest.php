<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_200(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'version',
                'services' => [
                    'database',
                    'asterisk',
                ],
                'app' => [
                    'name',
                    'env',
                    'debug',
                ],
                'cors',
            ]);
    }

    public function test_health_endpoint_returns_healthy_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'healthy',
            ]);
    }

    public function test_health_endpoint_returns_database_connected(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'services' => [
                    'database' => 'connected',
                ],
            ]);
    }

    public function test_health_endpoint_returns_app_info(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('app.env', 'testing')
            ->assertJsonPath('app.debug', true);
    }

    public function test_health_endpoint_returns_timestamp(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertNotEmpty($data['timestamp']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['timestamp']);
    }

    public function test_health_endpoint_returns_version(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('version', '1.0.0');
    }
}
