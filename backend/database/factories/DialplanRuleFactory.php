<?php

namespace Database\Factories;

use App\Models\DialplanRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DialplanRule>
 */
class DialplanRuleFactory extends Factory
{
    protected $model = DialplanRule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'context' => 'from-internal',
            'pattern' => '_1XX',
            'priority' => 1,
            'app' => 'Dial',
            'app_data' => 'PJSIP/${EXTEN},30',
            'enabled' => true,
            'rule_type' => 'pattern',
            'description' => $this->faker->sentence(),
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the rule is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    /**
     * Indicate that the rule is for internal extension calls.
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'rule_type' => 'internal',
            'pattern' => (string) $this->faker->numberBetween(100, 199),
            'app_data' => 'PJSIP/' . $this->faker->numberBetween(100, 199) . ',30',
        ]);
    }

    /**
     * Indicate that the rule is for outbound calls.
     */
    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'rule_type' => 'outbound',
            'pattern' => '_9X.',
            'app_data' => 'PJSIP/${EXTEN:1}@trunk,60',
            'description' => 'Outbound routing via trunk',
        ]);
    }

    /**
     * Indicate that the rule is for inbound calls.
     */
    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'context' => 'from-trunk',
            'rule_type' => 'inbound',
            'pattern' => 's',
            'app_data' => 'PJSIP/101,30',
            'description' => 'Inbound call routing',
        ]);
    }

    /**
     * Create the default internal pattern rule.
     */
    public function defaultPattern(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Internal Extension Calls',
            'context' => 'from-internal',
            'pattern' => '_1XX',
            'priority' => 1,
            'app' => 'Dial',
            'app_data' => 'PJSIP/${EXTEN},30',
            'enabled' => true,
            'rule_type' => 'pattern',
            'description' => 'Pattern match for extensions 100-199',
            'sort_order' => 0,
        ]);
    }
}
