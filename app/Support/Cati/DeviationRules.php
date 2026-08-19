<?php

namespace App\Support\Cati;

use App\Enums\ConformanceVerdict;
use App\Enums\SubmissionSectionKey;
use App\Models\Submission;

/**
 * The questions the committee actually asks, written as rules over data the
 * app already holds.
 *
 * Two kinds, and they come from different places on purpose:
 *
 * - **Standards questions are derived from `ConformanceChecks`** — one question
 *   per check that isn't `Ok`. They used to be duplicated here, with this class
 *   carrying its own copy of the keyword sets; the two drifting apart would
 *   have meant the checklist asking about something the conformance table had
 *   already marked green. Now a question exists exactly when a check needs an
 *   argument.
 * - **Completeness questions live here**, because they are not about a standard
 *   at all: a blank that is surprising given what the catalog knows, or a
 *   section the form leaves optional and the committee asks about every time.
 *
 * **A rule fires only when it is specifically interesting.** On a brand-new
 * submission everything is blank, and firing every rule would bury the two that
 * matter under seven that only repeat "nothing is filled in" — which the
 * section checklist already says.
 *
 * `severity` is `high` | `medium` | `low` — plain strings, like
 * DocumentationRequirements' `source`, since nothing outside this file branches
 * on them.
 */
class DeviationRules
{
    /** How much of the committee's time a failing check is worth. */
    private const SEVERITY = [
        'cloud_target'         => 'high',
        'sensitive_data'       => 'high',
        'contingency'          => 'high',
        'integration_platform' => 'medium',
        'observability'        => 'medium',
        'security'             => 'medium',
        'sdlc'                 => 'low',
    ];

    /** Which section each standards question belongs in. */
    private const SECTION = [
        'cloud_target'         => SubmissionSectionKey::Standards,
        'sdlc'                 => SubmissionSectionKey::Standards,
        'observability'        => SubmissionSectionKey::Standards,
        'security'             => SubmissionSectionKey::Standards,
        'integration_platform' => SubmissionSectionKey::Architecture,
        'sensitive_data'       => SubmissionSectionKey::DomainsData,
        'contingency'          => SubmissionSectionKey::PlanCosts,
    ];

    /**
     * @return list<array{key: string, section: string, question: string, why: string, severity: string}>
     */
    public static function for(Submission $submission): array
    {
        $submission->loadMissing(['sections', 'solution.vendor', 'solution.integrations']);

        return [
            ...self::fromConformance($submission),
            ...self::completeness($submission),
        ];
    }

    /**
     * One question per standard that still needs an argument.
     *
     * `Unknown` is skipped: it means the CATALOG is missing a field, which is a
     * gap in the record rather than something to interrogate the architect
     * about in an interview — except for the cloud, where the answer genuinely
     * is the architect's to give.
     *
     * @return list<array{key: string, section: string, question: string, why: string, severity: string}>
     */
    private static function fromConformance(Submission $submission): array
    {
        $content = $submission->sections->mapWithKeys(
            fn ($section) => [$section->key->value => (string) $section->content],
        );

        $rules = [];

        foreach (ConformanceChecks::for($submission) as $check) {
            if (! $check['verdict']->needsArgument()) {
                continue;
            }

            if ($check['verdict'] === ConformanceVerdict::Unknown && $check['key'] !== 'cloud_target') {
                continue;
            }

            $section = self::SECTION[$check['key']] ?? SubmissionSectionKey::Standards;

            // A VIOLATION always fires: departing from the target cloud is a
            // deviation whatever the sections happen to say. "Not stated"
            // (`Attention`) waits until that section HAS content — on a new
            // submission every section is blank, and asking about all of them
            // buries the useful questions under a restatement of what the
            // section checklist already says.
            if ($check['verdict'] === ConformanceVerdict::Attention && blank($content->get($section->value))) {
                continue;
            }

            $rules[] = [
                'key'      => $check['key'],
                'section'  => $section->value,
                'question' => $check['question'],
                'why'      => $check['detail'],
                'severity' => self::SEVERITY[$check['key']] ?? 'medium',
            ];
        }

        return $rules;
    }

    /**
     * Blanks that are surprising given what the catalog knows, plus the one
     * question the form doesn't require and the committee always asks.
     *
     * @return list<array{key: string, section: string, question: string, why: string, severity: string}>
     */
    private static function completeness(Submission $submission): array
    {
        $solution = $submission->solution;
        $content = $submission->sections->mapWithKeys(
            fn ($section) => [$section->key->value => (string) $section->content],
        );

        $rules = [];

        if ($solution?->contract_status === 'contracted' && $solution->vendor === null) {
            $rules[] = [
                'key'      => 'vendor_missing',
                'section'  => SubmissionSectionKey::PlanCosts->value,
                'question' => 'O catálogo diz que a solução é contratada, mas não tem fornecedor registrado. Quem é o fornecedor?',
                'why'      => 'contract_status = contracted, sem vendor_company_id',
                'severity' => 'medium',
            ];
        }

        $integrations = $solution?->integrations ?? collect();

        if ($integrations->isNotEmpty() && blank($content->get(SubmissionSectionKey::LegacyImpact->value))) {
            $rules[] = [
                'key'      => 'legacy_impact_blank',
                'section'  => SubmissionSectionKey::LegacyImpact->value,
                'question' => 'A solução já tem ' . $integrations->count() . ' integração(ões) catalogada(s) ('
                    . $integrations->pluck('name')->implode(', ') . '). Quais delas mudam, e há algo a descomissionar?',
                'why'      => 'Integrações existentes no inventário, sem impacto descrito',
                'severity' => 'high',
            ];
        }

        if (blank($content->get(SubmissionSectionKey::Alternatives->value))) {
            $rules[] = [
                'key'      => 'alternatives_blank',
                'section'  => SubmissionSectionKey::Alternatives->value,
                'question' => 'Que outras opções foram consideradas, e por que esta ganhou?',
                // Not mandatory on the form, so the section checklist stays
                // quiet about it — but the committee asks every single time.
                'why'      => 'Seção opcional no formulário, perguntada em toda deliberação',
                'severity' => 'low',
            ];
        }

        return $rules;
    }
}
