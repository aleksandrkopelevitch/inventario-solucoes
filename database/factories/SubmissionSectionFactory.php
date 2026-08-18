<?php

namespace Database\Factories;

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Models\Submission;
use App\Models\SubmissionSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionSection>
 */
class SubmissionSectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'key'           => SubmissionSectionKey::Summary,
            'content'       => null,
            'state'         => SubmissionSectionState::Empty,
            'provenance'    => null,
            'updated_by_id' => null,
        ];
    }

    public function drafted(string $content = 'Rascunho proposto pelo assistente.'): static
    {
        return $this->state(fn () => [
            'content' => $content,
            'state'   => SubmissionSectionState::Drafted,
        ]);
    }

    public function confirmed(string $content = 'Conteúdo confirmado por um humano.'): static
    {
        return $this->state(fn () => [
            'content' => $content,
            'state'   => SubmissionSectionState::Confirmed,
        ]);
    }
}
