<?php

namespace Database\Factories;

use App\Models\FlowspecGuideline;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FlowspecGuideline>
 */
class FlowspecGuidelineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title'     => $title,
            'slug'      => Str::slug($title),
            'content'   => fake()->paragraphs(3, true),
            'source'    => 'manual',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
