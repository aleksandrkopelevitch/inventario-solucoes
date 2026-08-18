<?php

namespace Database\Factories;

use App\Enums\SubmissionSourceExtraction;
use App\Enums\SubmissionSourceKind;
use App\Models\Submission;
use App\Models\SubmissionSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionSource>
 */
class SubmissionSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id'    => Submission::factory(),
            'kind'             => SubmissionSourceKind::Upload,
            'label'            => 'CATI_exemplo.pptx',
            'url'              => null,
            'media_id'         => null,
            'extracted_text'   => 'Slide 1: Título' . PHP_EOL . 'Slide 2: Propósito',
            'extraction_state' => SubmissionSourceExtraction::Done,
            'extraction_note'  => null,
        ];
    }

    /** A PDF/image: not a failure — it rides along as a native attachment instead. */
    public function skipped(): static
    {
        return $this->state(fn () => [
            'label'            => 'arquitetura.pdf',
            'extracted_text'   => null,
            'extraction_state' => SubmissionSourceExtraction::Skipped,
            'extraction_note'  => 'PDF vai como anexo nativo para o modelo.',
        ]);
    }

    public function link(string $url = 'https://dev.azure.com/leomadeiras/wiki/CATI'): static
    {
        return $this->state(fn () => [
            'kind'             => SubmissionSourceKind::Link,
            'label'            => 'Wiki do CATI',
            'url'              => $url,
            'extracted_text'   => null,
            'extraction_state' => SubmissionSourceExtraction::Skipped,
        ]);
    }
}
