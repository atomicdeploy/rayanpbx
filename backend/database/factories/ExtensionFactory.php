<?php

namespace Database\Factories;

use App\Models\Extension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Extension>
 */
class ExtensionFactory extends Factory
{
    protected $model = Extension::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'extension_number' => (string) $this->faker->unique()->numberBetween(100, 999),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'secret' => bcrypt('password123'),
            'enabled' => true,
            'context' => 'from-internal',
            'transport' => 'udp',
            'codecs' => ['ulaw', 'alaw', 'g722'],
            'max_contacts' => 1,
            'direct_media' => 'no',
            'qualify_frequency' => 60,
            'caller_id' => null,
            'voicemail_enabled' => false,
            'notes' => null,
        ];
    }

    /**
     * Indicate that the extension is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    /**
     * Indicate that the extension has voicemail enabled.
     */
    public function withVoicemail(): static
    {
        return $this->state(fn (array $attributes) => [
            'voicemail_enabled' => true,
        ]);
    }

    /**
     * Indicate that the extension uses TLS transport.
     */
    public function tls(): static
    {
        return $this->state(fn (array $attributes) => [
            'transport' => 'tls',
        ]);
    }
}
