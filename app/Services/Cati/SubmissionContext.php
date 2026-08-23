<?php

namespace App\Services\Cati;

use App\Models\CatiExample;
use App\Models\CatiGuideline;
use Illuminate\Support\Collection;

/**
 * Everything one turn of the interview gets to see. Assembled by
 * SubmissionContextResolver; audited into the reply's `meta`.
 */
final class SubmissionContext
{
    /**
     * @param  array{facts: list<array<string, mixed>>, structural: list<array<string, mixed>>, sections: list<array<string, mixed>>}  $requirements
     * @param  list<array{key: string, section: string, question: string, why: string, severity: string}>  $deviations
     * @param  Collection<int, array{label: string, text: string, flagged: list<string>}>  $textSources
     * @param  list<object>  $attachments
     * @param  list<array{id: int, name: string, kind: string}>  $attachedMeta
     * @param  list<string>  $omittedSources
     * @param  Collection<int, CatiGuideline>  $guidelines
     * @param  Collection<int, CatiExample>  $examples
     */
    public function __construct(
        public readonly array $requirements,
        public readonly array $deviations,
        public readonly Collection $textSources,
        public readonly array $attachments,
        public readonly array $attachedMeta,
        public readonly array $omittedSources,
        public readonly Collection $guidelines,
        public readonly Collection $examples,
        public readonly bool $examplesByTag,
    ) {}
}
