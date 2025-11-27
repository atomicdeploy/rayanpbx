<?php

namespace Tests\Unit\Models;

use App\Models\VoipPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoipPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_voip_phone(): void
    {
        $phone = VoipPhone::factory()->create([
            'ip' => '192.168.1.100',
            'mac' => '00:11:22:33:44:55',
        ]);

        $this->assertDatabaseHas('voip_phones', [
            'ip' => '192.168.1.100',
            'mac' => '00:11:22:33:44:55',
        ]);
    }

    public function test_voip_phone_has_fillable_attributes(): void
    {
        $phone = new VoipPhone();

        $this->assertContains('ip', $phone->getFillable());
        $this->assertContains('mac', $phone->getFillable());
        $this->assertContains('extension', $phone->getFillable());
        $this->assertContains('name', $phone->getFillable());
        $this->assertContains('vendor', $phone->getFillable());
        $this->assertContains('model', $phone->getFillable());
        $this->assertContains('status', $phone->getFillable());
    }

    public function test_voip_phone_casts_cti_enabled_to_boolean(): void
    {
        $phone = VoipPhone::factory()->create(['cti_enabled' => 1]);

        $this->assertIsBool($phone->cti_enabled);
        $this->assertTrue($phone->cti_enabled);
    }

    public function test_voip_phone_casts_snmp_enabled_to_boolean(): void
    {
        $phone = VoipPhone::factory()->create(['snmp_enabled' => 1]);

        $this->assertIsBool($phone->snmp_enabled);
        $this->assertTrue($phone->snmp_enabled);
    }

    public function test_voip_phone_casts_snmp_config_to_array(): void
    {
        $config = ['community' => 'public', 'version' => '2c'];
        $phone = VoipPhone::factory()->create(['snmp_config' => $config]);

        $this->assertIsArray($phone->snmp_config);
        $this->assertEquals('public', $phone->snmp_config['community']);
    }

    public function test_voip_phone_casts_config_to_array(): void
    {
        $config = ['setting1' => 'value1'];
        $phone = VoipPhone::factory()->create(['config' => $config]);

        $this->assertIsArray($phone->config);
        $this->assertEquals('value1', $phone->config['setting1']);
    }

    public function test_voip_phone_casts_last_seen_to_datetime(): void
    {
        $phone = VoipPhone::factory()->create(['last_seen' => now()]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $phone->last_seen);
    }

    public function test_voip_phone_hides_credentials(): void
    {
        $phone = VoipPhone::factory()->create();

        $this->assertArrayNotHasKey('credentials', $phone->toArray());
    }

    public function test_online_factory_state(): void
    {
        $phone = VoipPhone::factory()->online()->create();

        $this->assertEquals('online', $phone->status);
    }

    public function test_offline_factory_state(): void
    {
        $phone = VoipPhone::factory()->offline()->create();

        $this->assertEquals('offline', $phone->status);
    }

    public function test_registered_factory_state(): void
    {
        $phone = VoipPhone::factory()->registered()->create();

        $this->assertEquals('registered', $phone->status);
    }

    public function test_with_cti_factory_state(): void
    {
        $phone = VoipPhone::factory()->withCti()->create();

        $this->assertTrue($phone->cti_enabled);
    }

    public function test_with_snmp_factory_state(): void
    {
        $phone = VoipPhone::factory()->withSnmp()->create();

        $this->assertTrue($phone->snmp_enabled);
        $this->assertNotNull($phone->snmp_config);
    }

    public function test_voip_phone_ip_is_unique(): void
    {
        VoipPhone::factory()->create(['ip' => '192.168.1.1']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        VoipPhone::factory()->create(['ip' => '192.168.1.1']);
    }

    public function test_scope_online(): void
    {
        VoipPhone::factory()->online()->count(2)->create();
        VoipPhone::factory()->offline()->count(3)->create();

        $onlinePhones = VoipPhone::online()->get();

        $this->assertCount(2, $onlinePhones);
    }

    public function test_scope_registered(): void
    {
        VoipPhone::factory()->registered()->count(2)->create();
        VoipPhone::factory()->create(['status' => 'discovered']);

        $registeredPhones = VoipPhone::registered()->get();

        $this->assertCount(2, $registeredPhones);
    }

    public function test_scope_grandstream(): void
    {
        VoipPhone::factory()->create(['vendor' => 'grandstream']);
        VoipPhone::factory()->create(['vendor' => 'yealink']);

        $grandstreamPhones = VoipPhone::grandstream()->get();

        $this->assertCount(1, $grandstreamPhones);
    }

    public function test_scope_cti_enabled(): void
    {
        VoipPhone::factory()->withCti()->count(2)->create();
        VoipPhone::factory()->create(['cti_enabled' => false]);

        $ctiPhones = VoipPhone::ctiEnabled()->get();

        $this->assertCount(2, $ctiPhones);
    }

    public function test_mark_online(): void
    {
        $phone = VoipPhone::factory()->offline()->create();

        $phone->markOnline();

        $this->assertEquals('online', $phone->fresh()->status);
        $this->assertNotNull($phone->fresh()->last_seen);
    }

    public function test_mark_offline(): void
    {
        $phone = VoipPhone::factory()->online()->create();

        $phone->markOffline();

        $this->assertEquals('offline', $phone->fresh()->status);
    }

    public function test_touch_updates_last_seen(): void
    {
        $phone = VoipPhone::factory()->create(['last_seen' => now()->subDay()]);
        $originalLastSeen = $phone->last_seen;

        $phone->touch();

        $this->assertTrue($phone->fresh()->last_seen > $originalLastSeen);
    }

    public function test_get_display_name_with_name(): void
    {
        $phone = VoipPhone::factory()->create(['name' => 'Reception Phone']);

        $this->assertEquals('Reception Phone', $phone->getDisplayName());
    }

    public function test_get_display_name_with_extension(): void
    {
        $phone = VoipPhone::factory()->create(['name' => null, 'extension' => '101']);

        $this->assertEquals('Phone 101', $phone->getDisplayName());
    }

    public function test_get_display_name_with_model(): void
    {
        $phone = VoipPhone::factory()->create([
            'name' => null,
            'extension' => null,
            'model' => 'GXP1625',
            'ip' => '192.168.1.100',
        ]);

        $this->assertEquals('GXP1625 (192.168.1.100)', $phone->getDisplayName());
    }

    public function test_get_display_name_fallback_to_ip(): void
    {
        $phone = VoipPhone::factory()->create([
            'name' => null,
            'extension' => null,
            'model' => null,
            'ip' => '192.168.1.100',
        ]);

        $this->assertEquals('192.168.1.100', $phone->getDisplayName());
    }

    public function test_has_credentials_returns_false_when_empty(): void
    {
        $phone = VoipPhone::factory()->create(['credentials' => null]);

        $this->assertFalse($phone->hasCredentials());
    }

    public function test_get_credentials_for_api_returns_defaults_when_empty(): void
    {
        $phone = VoipPhone::factory()->create(['credentials' => null]);

        $creds = $phone->getCredentialsForApi();

        $this->assertEquals('admin', $creds['username']);
        $this->assertEquals('', $creds['password']);
    }
}
