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
        ];
    }

    public function kind(SubmissionDiagramKind $kind): static
    {
        return $this->state(fn () => ['kind' => $kind]);
    }
}
