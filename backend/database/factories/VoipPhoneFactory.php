<?php

namespace Database\Factories;

use App\Models\VoipPhone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VoipPhone>
 */
class VoipPhoneFactory extends Factory
{
    protected $model = VoipPhone::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip' => $this->faker->unique()->ipv4(),
            'mac' => $this->faker->unique()->macAddress(),
            'extension' => (string) $this->faker->numberBetween(100, 999),
            'name' => 'Phone ' . $this->faker->word(),
            'vendor' => 'grandstream',
            'model' => $this->faker->randomElement(['GXP1625', 'GXP1628', 'GXP2135', 'GXP2170']),
            'firmware' => '1.0.' . $this->faker->numberBetween(1, 99) . '.0',
            'status' => 'discovered',
            'discovery_type' => 'lldp',
            'user_agent' => 'Grandstream GXP1625',
            'cti_enabled' => false,
            'snmp_enabled' => false,
            'snmp_config' => null,
            'credentials' => null,
            'config' => null,
            'last_seen' => now(),
        ];
    }

    /**
     * Indicate that the phone is online.
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'online',
            'last_seen' => now(),
        ]);
    }

    /**
     * Indicate that the phone is offline.
     */
    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'offline',
        ]);
    }

    /**
     * Indicate that the phone is registered.
     */
    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'registered',
            'last_seen' => now(),
        ]);
    }

    /**
     * Indicate that the phone has CTI enabled.
     */
    public function withCti(): static
    {
        return $this->state(fn (array $attributes) => [
            'cti_enabled' => true,
        ]);
    }

    /**
     * Indicate that the phone has SNMP enabled.
     */
    public function withSnmp(): static
    {
        return $this->state(fn (array $attributes) => [
            'snmp_enabled' => true,
            'snmp_config' => [
                'community' => 'public',
                'version' => '2c',
            ],
        ]);
    }
}
