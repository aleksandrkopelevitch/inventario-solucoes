<?php

namespace Database\Factories;

use App\Enums\SubmissionDiagramKind;
use App\Models\Submission;
use App\Models\SubmissionDiagram;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionDiagram> */
class SubmissionDiagramFactory extends Factory
{
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'kind'          => SubmissionDiagramKind::ToBe,
            // The seeded root, same shape a brand-new Integration is born
            // with — a canvas needs somewhere to hang the first gesture.
            'chain'      => ['nodes' => [['solution_id' => null, 'label' => 'Sistema', 'kind' => 'system']], 'edges' => []],
            'viz_layout' => null,
        ];
    }

    /** A drawing with more than the seeded root — what `isFilled()` counts. */
    public function drawn(): static
    {
        return $this->state(fn () => ['chain' => [
            'nodes' => [
                ['solution_id' => null, 'label' => 'Sistema', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
        ]]);
    }

    public function kind(SubmissionDiagramKind $kind): static
    {
        return $this->state(fn () => [
            'kind'  => $kind,
            'chain' => $kind->isDrawn() ? $this->definition()['chain'] : null,
        ]);
    }
}
