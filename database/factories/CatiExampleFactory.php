<?php

namespace Database\Factories;

use App\Enums\SubmissionSectionKey;
use App\Models\CatiExample;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CatiExample>
 */
class CatiExampleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'CATI ' . $this->faker->unique()->words(2, true);

        return [
            'name'     => $name,
            'slug'     => Str::slug($name),
            'summary'  => 'Submissão aprovada, usada como exemplo.',
            'sections' => [
                SubmissionSectionKey::Summary->value      => 'Estabelecer um ponto único e controlado de conexão entre a plataforma SaaS e a operação local.',
                SubmissionSectionKey::Architecture->value => 'VM dedicada (IaaS) na Google Cloud, com VPN Site-to-Site para a Central.',
            ],
            'tags'      => ['integracao', 'gcp'],
            'source'    => 'CATI_exemplo.pptx',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
