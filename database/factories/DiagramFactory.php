<?php

namespace Database\Factories;

use App\Enums\Direction;
use App\Enums\DiagramStatus;
use App\Enums\Protocol;
use App\Enums\SyncMode;
use App\Models\Diagram;
use App\Models\Solution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Diagram>
 */
class DiagramFactory extends Factory
{
    protected $model = Diagram::class;

    public function definition(): array
    {
        $name = 'Diagrama ' . fake()->unique()->numberBetween(1, 1000000);

        return [
            'name'               => $name,
            'slug'               => 'dia-' . Str::lower(Str::random(8)),
            'source_solution_id' => Solution::factory(),
            'target_solution_id' => Solution::factory(),
            'direction'          => Direction::Unidirectional,
            'protocol'           => Protocol::Rest->value,
            'sync_mode'          => SyncMode::Synchronous,
            'status'             => DiagramStatus::Planned,
            'criticality'        => 'medium',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => DiagramStatus::Active]);
    }
}
