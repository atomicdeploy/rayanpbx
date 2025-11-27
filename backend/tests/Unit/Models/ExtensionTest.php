<?php

namespace Tests\Unit\Models;

use App\Models\Extension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_extension(): void
    {
        $extension = Extension::factory()->create([
            'extension_number' => '101',
            'name' => 'Test User',
        ]);

        $this->assertDatabaseHas('extensions', [
            'extension_number' => '101',
            'name' => 'Test User',
        ]);
    }

    public function test_extension_has_fillable_attributes(): void
    {
        $extension = new Extension();

        $this->assertContains('extension_number', $extension->getFillable());
        $this->assertContains('name', $extension->getFillable());
        $this->assertContains('email', $extension->getFillable());
        $this->assertContains('secret', $extension->getFillable());
        $this->assertContains('enabled', $extension->getFillable());
        $this->assertContains('context', $extension->getFillable());
        $this->assertContains('transport', $extension->getFillable());
        $this->assertContains('codecs', $extension->getFillable());
    }

    public function test_extension_casts_enabled_to_boolean(): void
    {
        $extension = Extension::factory()->create(['enabled' => 1]);

        $this->assertIsBool($extension->enabled);
        $this->assertTrue($extension->enabled);
    }

    public function test_extension_casts_voicemail_enabled_to_boolean(): void
    {
        $extension = Extension::factory()->create(['voicemail_enabled' => 1]);

        $this->assertIsBool($extension->voicemail_enabled);
        $this->assertTrue($extension->voicemail_enabled);
    }

    public function test_extension_casts_codecs_to_array(): void
    {
        $extension = Extension::factory()->create([
            'codecs' => ['ulaw', 'alaw', 'g722'],
        ]);

        $this->assertIsArray($extension->codecs);
        $this->assertContains('ulaw', $extension->codecs);
    }

    public function test_extension_hides_secret(): void
    {
        $extension = Extension::factory()->create();

        $this->assertArrayNotHasKey('secret', $extension->toArray());
    }

    public function test_disabled_factory_state(): void
    {
        $extension = Extension::factory()->disabled()->create();

        $this->assertFalse($extension->enabled);
    }

    public function test_voicemail_factory_state(): void
    {
        $extension = Extension::factory()->withVoicemail()->create();

        $this->assertTrue($extension->voicemail_enabled);
    }

    public function test_tls_factory_state(): void
    {
        $extension = Extension::factory()->tls()->create();

        $this->assertEquals('tls', $extension->transport);
    }

    public function test_extension_number_is_unique(): void
    {
        Extension::factory()->create(['extension_number' => '100']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Extension::factory()->create(['extension_number' => '100']);
    }

    public function test_can_update_extension(): void
    {
        $extension = Extension::factory()->create(['name' => 'Original Name']);

        $extension->update(['name' => 'Updated Name']);

        $this->assertEquals('Updated Name', $extension->fresh()->name);
    }

    public function test_can_delete_extension(): void
    {
        $extension = Extension::factory()->create();
        $extensionId = $extension->id;

        $extension->delete();

        $this->assertDatabaseMissing('extensions', ['id' => $extensionId]);
    }
}
