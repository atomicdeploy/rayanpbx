<?php

namespace Tests\Feature\Api;

use App\Models\Extension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionControllerTest extends TestCase
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

    public function test_list_extensions_requires_authentication(): void
    {
        $response = $this->getJson('/api/extensions');

        $response->assertStatus(401);
    }

    public function test_list_extensions_returns_all_extensions(): void
    {
        Extension::factory()->count(3)->create();

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/extensions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'extensions',
                'asterisk_endpoints',
            ])
            ->assertJsonCount(3, 'extensions');
    }

    public function test_create_extension_requires_authentication(): void
    {
        $response = $this->postJson('/api/extensions', [
            'extension_number' => '101',
            'name' => 'Test User',
            'secret' => 'password123',
        ]);

        $response->assertStatus(401);
    }

    public function test_create_extension_validates_required_fields(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/extensions', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['extension_number', 'name', 'secret']);
    }

    public function test_create_extension_validates_extension_number_uniqueness(): void
    {
        Extension::factory()->create(['extension_number' => '101']);

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/extensions', [
            'extension_number' => '101',
            'name' => 'Test User',
            'secret' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['extension_number']);
    }

    public function test_create_extension_validates_secret_minimum_length(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/extensions', [
            'extension_number' => '101',
            'name' => 'Test User',
            'secret' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['secret']);
    }

    public function test_create_extension_validates_transport(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/extensions', [
            'extension_number' => '101',
            'name' => 'Test User',
            'secret' => 'password123',
            'transport' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['transport']);
    }

    public function test_create_extension_validates_codecs(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api/extensions', [
            'extension_number' => '101',
            'name' => 'Test User',
            'secret' => 'password123',
            'codecs' => ['invalid_codec'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['codecs.0']);
    }

    public function test_show_extension_requires_authentication(): void
    {
        $extension = Extension::factory()->create();

        $response = $this->getJson("/api/extensions/{$extension->id}");

        $response->assertStatus(401);
    }

    public function test_show_extension_returns_extension(): void
    {
        $extension = Extension::factory()->create([
            'extension_number' => '101',
            'name' => 'Test User',
        ]);

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/extensions/{$extension->id}");

        $response->assertStatus(200)
            ->assertJsonPath('extension.extension_number', '101')
            ->assertJsonPath('extension.name', 'Test User');
    }

    public function test_show_extension_returns_404_for_nonexistent(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/extensions/99999');

        $response->assertStatus(404);
    }

    public function test_update_extension_requires_authentication(): void
    {
        $extension = Extension::factory()->create();

        $response = $this->putJson("/api/extensions/{$extension->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(401);
    }

    public function test_delete_extension_requires_authentication(): void
    {
        $extension = Extension::factory()->create();

        $response = $this->deleteJson("/api/extensions/{$extension->id}");

        $response->assertStatus(401);
    }

    public function test_toggle_extension_requires_authentication(): void
    {
        $extension = Extension::factory()->create();

        $response = $this->postJson("/api/extensions/{$extension->id}/toggle");

        $response->assertStatus(401);
    }

    public function test_verify_extension_requires_authentication(): void
    {
        $extension = Extension::factory()->create();

        $response = $this->getJson("/api/extensions/{$extension->id}/verify");

        $response->assertStatus(401);
    }

    public function test_verify_extension_returns_status(): void
    {
        $extension = Extension::factory()->create();

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/extensions/{$extension->id}/verify");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'extension',
                'exists_in_asterisk',
                'registration_status',
                'endpoint_details',
            ]);
    }

    public function test_diagnostics_extension_requires_authentication(): void
    {
        $extension = Extension::factory()->create();

        $response = $this->getJson("/api/extensions/{$extension->id}/diagnostics");

        $response->assertStatus(401);
    }

    public function test_diagnostics_extension_returns_guide(): void
    {
        $extension = Extension::factory()->create();

        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson("/api/extensions/{$extension->id}/diagnostics");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'extension',
                'registration_status',
                'endpoint_details',
                'setup_guide',
                'sip_clients',
                'troubleshooting',
                'test_instructions',
                'api_endpoints',
            ]);
    }

    public function test_asterisk_endpoints_requires_authentication(): void
    {
        $response = $this->getJson('/api/extensions/asterisk/endpoints');

        $response->assertStatus(401);
    }

    public function test_asterisk_endpoints_returns_endpoints(): void
    {
        $token = $this->getAuthToken();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/extensions/asterisk/endpoints');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'endpoints',
                'total',
            ]);
    }
}
