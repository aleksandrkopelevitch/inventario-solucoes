<?php

namespace Database\Factories;

use App\Enums\Direction;
use App\Enums\IntegrationStatus;
use App\Enums\Protocol;
use App\Enums\SyncMode;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        $name = 'Integração ' . fake()->unique()->numberBetween(1, 1000000);

        return [
            'name'               => $name,
            'slug'               => 'int-' . Str::lower(Str::random(8)),
            'source_solution_id' => Solution::factory(),
            'target_solution_id' => Solution::factory(),
            'direction'          => Direction::Unidirectional,
            'protocol'           => Protocol::Rest,
            'sync_mode'          => SyncMode::Synchronous,
            'status'             => IntegrationStatus::Planned,
            'criticality'        => 'medium',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => IntegrationStatus::Active]);
    }
}
