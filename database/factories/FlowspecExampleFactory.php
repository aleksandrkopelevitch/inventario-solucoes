<?php

namespace Database\Factories;

use App\Enums\FlowspecTag;
use App\Models\FlowspecExample;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FlowspecExample>
 */
class FlowspecExampleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->sentence(3);

        return [
            'name'        => $name,
            'slug'        => Str::slug($name),
            'description' => fake()->paragraph(),
            'tags'        => fake()->randomElements(FlowspecTag::values(), 2),
            'flow_spec'   => [
                'meta'     => [],
                'flowSpec' => [],
            ],
            'connectors' => [],
            'source'     => 'manual',
            'is_active'  => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** @param list<string> $tags */
    public function tagged(array $tags): static
    {
        return $this->state(['tags' => $tags]);
    }
}
