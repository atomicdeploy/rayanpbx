<?php

namespace Database\Factories;

use App\Models\Trunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Trunk>
 */
class TrunkFactory extends Factory
{
    protected $model = Trunk::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->regexify('trunk[0-9]{3}'),
            'type' => 'peer',
            'host' => $this->faker->ipv4(),
            'port' => 5060,
            'username' => $this->faker->userName(),
            'secret' => bcrypt('trunksecret'),
            'enabled' => true,
            'transport' => 'udp',
            'codecs' => ['ulaw', 'alaw', 'g722'],
            'context' => 'from-trunk',
            'priority' => 1,
            'prefix' => '9',
            'strip_digits' => 1,
            'max_channels' => 10,
            'notes' => null,
        ];
    }

    /**
     * Indicate that the trunk is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    /**
     * Indicate a high-priority trunk.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 10,
        ]);
    }

    /**
     * Indicate that the trunk uses TLS transport.
     */
    public function tls(): static
    {
        return $this->state(fn (array $attributes) => [
            'transport' => 'tls',
            'port' => 5061,
        ]);
    }
}
