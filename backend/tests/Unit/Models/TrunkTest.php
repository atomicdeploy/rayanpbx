<?php

namespace Tests\Unit\Models;

use App\Models\Trunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrunkTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_trunk(): void
    {
        $trunk = Trunk::factory()->create([
            'name' => 'test-trunk',
            'host' => '192.168.1.100',
        ]);

        $this->assertDatabaseHas('trunks', [
            'name' => 'test-trunk',
            'host' => '192.168.1.100',
        ]);
    }

    public function test_trunk_has_fillable_attributes(): void
    {
        $trunk = new Trunk();

        $this->assertContains('name', $trunk->getFillable());
        $this->assertContains('type', $trunk->getFillable());
        $this->assertContains('host', $trunk->getFillable());
        $this->assertContains('port', $trunk->getFillable());
        $this->assertContains('username', $trunk->getFillable());
        $this->assertContains('secret', $trunk->getFillable());
        $this->assertContains('enabled', $trunk->getFillable());
        $this->assertContains('transport', $trunk->getFillable());
        $this->assertContains('codecs', $trunk->getFillable());
    }

    public function test_trunk_casts_enabled_to_boolean(): void
    {
        $trunk = Trunk::factory()->create(['enabled' => 1]);

        $this->assertIsBool($trunk->enabled);
        $this->assertTrue($trunk->enabled);
    }

    public function test_trunk_casts_codecs_to_array(): void
    {
        $trunk = Trunk::factory()->create([
            'codecs' => ['ulaw', 'alaw', 'g722'],
        ]);

        $this->assertIsArray($trunk->codecs);
        $this->assertContains('ulaw', $trunk->codecs);
    }

    public function test_trunk_casts_priority_to_integer(): void
    {
        $trunk = Trunk::factory()->create(['priority' => '5']);

        $this->assertIsInt($trunk->priority);
        $this->assertEquals(5, $trunk->priority);
    }

    public function test_trunk_casts_strip_digits_to_integer(): void
    {
        $trunk = Trunk::factory()->create(['strip_digits' => '2']);

        $this->assertIsInt($trunk->strip_digits);
        $this->assertEquals(2, $trunk->strip_digits);
    }

    public function test_trunk_casts_max_channels_to_integer(): void
    {
        $trunk = Trunk::factory()->create(['max_channels' => '20']);

        $this->assertIsInt($trunk->max_channels);
        $this->assertEquals(20, $trunk->max_channels);
    }

    public function test_trunk_hides_secret(): void
    {
        $trunk = Trunk::factory()->create();

        $this->assertArrayNotHasKey('secret', $trunk->toArray());
    }

    public function test_disabled_factory_state(): void
    {
        $trunk = Trunk::factory()->disabled()->create();

        $this->assertFalse($trunk->enabled);
    }

    public function test_high_priority_factory_state(): void
    {
        $trunk = Trunk::factory()->highPriority()->create();

        $this->assertEquals(10, $trunk->priority);
    }

    public function test_tls_factory_state(): void
    {
        $trunk = Trunk::factory()->tls()->create();

        $this->assertEquals('tls', $trunk->transport);
        $this->assertEquals(5061, $trunk->port);
    }

    public function test_trunk_name_is_unique(): void
    {
        Trunk::factory()->create(['name' => 'unique-trunk']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Trunk::factory()->create(['name' => 'unique-trunk']);
    }

    public function test_can_update_trunk(): void
    {
        $trunk = Trunk::factory()->create(['host' => '10.0.0.1']);

        $trunk->update(['host' => '10.0.0.2']);

        $this->assertEquals('10.0.0.2', $trunk->fresh()->host);
    }

    public function test_can_delete_trunk(): void
    {
        $trunk = Trunk::factory()->create();
        $trunkId = $trunk->id;

        $trunk->delete();

        $this->assertDatabaseMissing('trunks', ['id' => $trunkId]);
    }

    public function test_trunk_default_values(): void
    {
        $trunk = Trunk::factory()->create();

        $this->assertEquals('peer', $trunk->type);
        $this->assertEquals(5060, $trunk->port);
        $this->assertEquals('from-trunk', $trunk->context);
        $this->assertEquals('9', $trunk->prefix);
    }
}
