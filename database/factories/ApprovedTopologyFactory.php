<?php

namespace Database\Factories;

use App\Models\ApprovedTopology;
use App\Models\Solution;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApprovedTopology> */
class ApprovedTopologyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'solution_id'   => Solution::factory(),
            'chain'         => [
                'nodes' => [
                    ['solution_id' => null, 'label' => 'SKBridge', 'kind' => 'system'],
                    ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'],
                ],
                'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
            ],
            'viz_layout'  => ['nodes' => [['x' => 0, 'y' => 0], ['x' => 320, 'y' => 0]]],
            'approved_at' => now(),
        ];
    }
}
