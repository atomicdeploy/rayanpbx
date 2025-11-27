<?php

namespace Tests\Feature\Api;

use App\Models\Trunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrunkControllerTest extends TestCase
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

    public function test_list_trunks_requires_authentication(): void
    {
        $response = $this->getJson('/api/trunks');

        $response->assertStatus(401);
    }

    public function test_list_trunks_returns_all_trunks(): void
    {
        Trunk::factory()->count(3)->create();

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/trunks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'trunks',
            ]);
    }

    public function test_create_trunk_requires_authentication(): void
    {
        $response = $this->postJson('/api/trunks', [
            'name' => 'test-trunk',
            'host' => '192.168.1.100',
        ]);

        $response->assertStatus(401);
    }

    public function test_show_trunk_requires_authentication(): void
    {
        $trunk = Trunk::factory()->create();

        $response = $this->getJson("/api/trunks/{$trunk->id}");

        $response->assertStatus(401);
    }

    public function test_show_trunk_returns_trunk(): void
    {
        $trunk = Trunk::factory()->create([
            'name' => 'test-trunk',
            'host' => '192.168.1.100',
        ]);

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/trunks/{$trunk->id}");

        $response->assertStatus(200)
            ->assertJsonPath('trunk.name', 'test-trunk')
            ->assertJsonPath('trunk.host', '192.168.1.100');
    }

    public function test_show_trunk_returns_404_for_nonexistent(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/trunks/99999');

        $response->assertStatus(404);
    }

    public function test_update_trunk_requires_authentication(): void
    {
        $trunk = Trunk::factory()->create();

        $response = $this->putJson("/api/trunks/{$trunk->id}", [
            'host' => '10.0.0.1',
        ]);

        $response->assertStatus(401);
    }

    public function test_delete_trunk_requires_authentication(): void
    {
        $trunk = Trunk::factory()->create();

        $response = $this->deleteJson("/api/trunks/{$trunk->id}");

        $response->assertStatus(401);
    }
}
