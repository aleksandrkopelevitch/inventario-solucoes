<?php

namespace App\Support\Cati;

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Models\AttributeOption;
use App\Models\Submission;

/**
 * The deterministic, non-AI checklist for a submission — the single most
 * important thing this module does, and the reason it lives inside the
 * inventory instead of in a document tool.
 *
 * It answers two different questions, and keeping them apart is the point:
 *
 * - **`facts`** — what the catalog already knows about the Solution. These are
 *   reported so they can be CONFIRMED, and must never be turned into questions:
 *   asking an architect which cloud their own system runs on, when the record
 *   right there says `gcp`, is exactly the friction this replaces.
 * - **`sections` / `structural`** — what genuinely nobody has answered yet.
 *
 * Mirrors App\Support\Documentation\DocumentationRequirements: advisory only,
 * never blocks saving or submitting, and feeds both the checklist widget and
 * the interview's prompt so the assistant can't ask about something it can
 * already read.
 */
class SubmissionRequirements
{
    /**
     * A fact carries the sections it INFORMS rather than being filed under one
     * of them: most of these inform several (the vendor shows up in the
     * summary, in the operating model and in the costs), and forcing a 1:1
     * mapping would throw that away.
     *
     * @return array{
     *     facts: list<array{key: string, label: string, value: string, sections: list<string>}>,
     *     structural: list<array{key: string, label: string, satisfied: bool, value: string|null}>,
     *     sections: list<array{key: string, label: string, mandatory: bool, state: string, answered: bool}>
     * }
     */
    public static function for(Submission $submission): array
    {
        // Explicit: strict mode does NOT arm on a single-model fetch, so an
        // unloaded relation here would lazy-load in silence, in any
        // environment — including inside the queued job that builds the prompt.
        $submission->loadMissing(['sections', 'sources', 'solution.vendor', 'solution.integrations']);

        return [
            'facts'      => self::facts($submission),
            'structural' => self::structural($submission),
            'sections'   => self::sections($submission),
        ];
    }

    /**
     * Only non-null columns become facts. A blank column is not knowledge —
     * it's a gap, and the interview is free to ask about it.
     *
     * @return list<array{key: string, label: string, value: string, sections: list<string>}>
     */
    private static function facts(Submission $submission): array
    {
        $solution = $submission->solution;

        if ($solution === null) {
            return [];
        }

        $attributes = [
            ['key'         => 'category', 'label' => 'Categoria', 'group' => 'category', 'value' => $solution->category,
                'sections' => [SubmissionSectionKey::Summary, SubmissionSectionKey::DomainsData]],
            ['key'         => 'directorate', 'label' => 'Diretoria responsável', 'group' => 'directorate', 'value' => $solution->directorate,
                'sections' => [SubmissionSectionKey::Summary]],
            ['key'         => 'environment', 'label' => 'Hospedagem', 'group' => 'environment', 'value' => $solution->environment,
                'sections' => [SubmissionSectionKey::Architecture, SubmissionSectionKey::OperatingModel]],
            ['key'         => 'cloud', 'label' => 'Nuvem', 'group' => 'cloud', 'value' => $solution->cloud,
                'sections' => [SubmissionSectionKey::Architecture, SubmissionSectionKey::Standards]],
            ['key'         => 'criticality', 'label' => 'Criticidade', 'group' => 'criticality', 'value' => $solution->criticality,
                'sections' => [SubmissionSectionKey::BenefitsRisks, SubmissionSectionKey::Standards]],
            ['key'         => 'support_type', 'label' => 'Tipo de suporte', 'group' => 'support_type', 'value' => $solution->support_type,
                'sections' => [SubmissionSectionKey::OperatingModel]],
            ['key'         => 'contract_status', 'label' => 'Situação contratual', 'group' => 'contract_status', 'value' => $solution->contract_status,
                'sections' => [SubmissionSectionKey::PlanCosts]],
        ];

        $facts = [];

        foreach ($attributes as $attribute) {
            if ($attribute['value'] === null) {
                continue;
            }

            $facts[] = [
                'key'   => $attribute['key'],
                'label' => $attribute['label'],
                // Falls back to the raw column value: `labelFor()` returns null
                // for a value with no matching `attribute_options` row, and a
                // fact that IS on the record must not come back blank just
                // because nobody registered a pretty label for it.
                'value'    => AttributeOption::labelFor($attribute['group'], $attribute['value']) ?? $attribute['value'],
                'sections' => array_map(fn (SubmissionSectionKey $key) => $key->value, $attribute['sections']),
            ];
        }

        if ($solution->vendor !== null) {
            $facts[] = [
                'key'      => 'vendor',
                'label'    => 'Fornecedor',
                'value'    => $solution->vendor->name,
                'sections' => [
                    SubmissionSectionKey::Summary->value,
                    SubmissionSectionKey::OperatingModel->value,
                    SubmissionSectionKey::PlanCosts->value,
                ],
            ];
        }

        if ($solution->integrations->isNotEmpty()) {
            $facts[] = [
                'key'      => 'integrations',
                'label'    => 'Integrações já catalogadas',
                'value'    => $solution->integrations->pluck('name')->implode(', '),
                'sections' => [
                    SubmissionSectionKey::LegacyImpact->value,
                    SubmissionSectionKey::Architecture->value,
                ],
            ];
        }

        return $facts;
    }

    /**
     * Things about the submission itself, not about the Solution.
     *
     * @return list<array{key: string, label: string, satisfied: bool, value: string|null}>
     */
    private static function structural(Submission $submission): array
    {
        $solution = $submission->solution;
        $sources = $submission->sources;

        return [
            [
                'key'       => 'solution',
                'label'     => 'Ligada a uma solução do catálogo',
                'satisfied' => $solution !== null,
                // Without it there are no facts at all and every question has
                // to be asked from scratch, so it is worth surfacing as a gap.
                'value' => $solution?->name,
            ],
            [
                'key'       => 'requester',
                'label'     => 'Solicitante identificado',
                'satisfied' => $submission->requester_person_id !== null,
                'value'     => null,
            ],
            [
                'key'       => 'sources',
                'label'     => 'Material anexado',
                'satisfied' => $sources->isNotEmpty(),
                'value'     => $sources->isEmpty() ? null : $sources->count() . ' arquivo(s)',
            ],
            [
                'key'       => 'ticket',
                'label'     => 'Chamado no Leo Resolve',
                'satisfied' => filled($submission->ticket_reference),
                'value'     => $submission->ticket_reference,
            ],
        ];
    }

    /**
     * Per-section status. `answered` reads the CONTENT, not the state: a
     * section can be marked confirmed and then emptied by an edit, and the
     * checklist has to tell the truth about what the committee will read.
     *
     * @return list<array{key: string, label: string, mandatory: bool, state: string, answered: bool}>
     */
    private static function sections(Submission $submission): array
    {
        $rows = $submission->sections->keyBy(fn ($section) => $section->key->value);

        return array_map(function (SubmissionSectionKey $key) use ($rows) {
            $section = $rows->get($key->value);

            return [
                'key'       => $key->value,
                'label'     => $key->label(),
                'mandatory' => $key->mandatory(),
                'state'     => ($section?->state ?? SubmissionSectionState::Empty)->value,
                'answered'  => (bool) $section?->isAnswered(),
            ];
        }, SubmissionSectionKey::cases());
    }

    /**
     * The mandatory sections still unanswered — what the submit button warns
     * about, and what the interview attacks first.
     *
     * @return list<string> section keys
     */
    public static function missingMandatory(Submission $submission): array
    {
        $submission->loadMissing('sections');

        return array_values(array_map(
            fn (array $section) => $section['key'],
            array_filter(
                self::sections($submission),
                fn (array $section) => $section['mandatory'] && ! $section['answered'],
            ),
        ));
    }
}
