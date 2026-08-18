<?php

namespace Database\Factories;

use App\Models\CatiGuideline;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CatiGuideline>
 */
class CatiGuidelineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = 'Diretriz ' . $this->faker->unique()->words(2, true);

        return [
            'title'     => $title,
            'slug'      => Str::slug($title),
            'content'   => '- Toda integração transacional passa pela Digibee.',
            'source'    => 'Wiki de arquitetura',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
