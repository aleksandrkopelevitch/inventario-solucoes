<?php

namespace App\Support\Documentation;

use App\Contracts\Documentable;
use App\Models\AttributeOption;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Support\Fold;

/**
 * Deterministic, non-AI "minimum requirements" checklist for a documentation
 * page — surfaced as an advisory widget next to the Documentation Assistant
 * chat AND fed into its prompt, so it never needs to ask about something it can
 * already tell. Never blocks Salvar; purely informational.
 *
 * The "hosting model / criticality / directorate" items are `source:
 * attribute` — already known from a Solution's record, so they're reported as
 * facts, never as gaps the user should fill in the chat — plus a few
 * best-effort content checks (keyword presence in the Markdown: honest, not a
 * quality judgment).
 *
 * There used to be a "drawing is actually drawn" item, read off the page's
 * `diagram_id`. A page cites drawings in its text now, so there is no one
 * drawing to check — and checking every cited one would put the checklist in
 * the business of auditing other records.
 *
 * Those attribute facts reach a Solution THROUGH the caderno
 * (`Notebook::solutions()`), which changes the shape in two ways worth knowing:
 * a caderno may describe SEVERAL solutions, in which case every one of them is
 * reported and each label says whose fact it is ("Hospedagem · GCP") — a fact
 * printed without its owner is worse than no fact; and a caderno may describe
 * NONE, in which case only the content checks are reported, which is the same
 * "quando for o caso" rule a standalone group always had.
 *
 * There used to be a second shape for an `Integration`, checking its
 * protocol/sync mode/participants and whether its chain had anything drawn on
 * it. Those are a diagram's properties, and a diagram no longer carries
 * documentation for a checklist to sit beside.
 */
class DocumentationRequirements
{
    /** @return list<array{key: string, label: string, satisfied: bool, source: string, value?: string}> */
    public static function for(Documentable $target, ?string $content = null): array
    {
        $content = (string) ($content ?? ($target->documentation ?? ''));

        return $target instanceof DocumentationPage
            ? self::forPage($target, $content)
            : [];
    }

    /**
     * Attribute facts for every solution the page's caderno documents, then the
     * drawing, then the content checks.
     *
     * @return list<array{key: string, label: string, satisfied: bool, source: string, value?: string}>
     */
    private static function forPage(DocumentationPage $page, string $content): array
    {
        $solutions = $page->notebook?->solutions ?? collect();
        $items = [];

        // Suffixed with the solution's name only when there is more than one to
        // tell apart — "Hospedagem · GCP" is clarifying when three systems are
        // listed and noise when one is.
        $qualify = $solutions->count() > 1;

        foreach ($solutions as $solution) {
            foreach (self::attributesOf($solution) as $attr) {
                $items[] = [
                    'key'       => $qualify ? "{$attr['key']}:{$solution->id}" : $attr['key'],
                    'label'     => $qualify ? "{$attr['label']} · {$solution->name}" : $attr['label'],
                    'satisfied' => $attr['value'] !== null,
                    'source'    => 'attribute',
                    'value'     => AttributeOption::labelFor($attr['group'], $attr['value']),
                ];
            }
        }

        return [...$items, ...self::contentItems($content)];
    }

    /**
     * `category`/`status`/`support_type` are NOT NULL columns on `solutions`
     * (always set), so they'd never actually flag a gap — only the genuinely
     * optional attributes are worth checking here.
     *
     * @return list<array{key: string, label: string, group: string, value: string|null}>
     */
    private static function attributesOf(Solution $solution): array
    {
        return [
            ['key' => 'environment', 'label' => 'Hospedagem', 'group' => 'environment', 'value' => $solution->environment],
            ['key' => 'criticality', 'label' => 'Criticidade', 'group' => 'criticality', 'value' => $solution->criticality],
            ['key' => 'directorate', 'label' => 'Diretoria responsável', 'group' => 'directorate', 'value' => $solution->directorate],
        ];
    }

    /** Best-effort, keyword-based content checks. */
    private static function contentItems(string $content): array
    {
        return [
            [
                'key'       => 'overview',
                'label'     => 'Visão geral descrita',
                'satisfied' => mb_strlen(trim($content)) >= 80,
                'source'    => 'content',
            ],
            [
                'key'       => 'error_handling',
                'label'     => 'Tratamento de erros / contingência descrito',
                'satisfied' => self::mentions($content, ['erro', 'falha', 'contingência', 'retry', 'reprocess']),
                'source'    => 'content',
            ],
            [
                'key'       => 'contact',
                'label'     => 'Contato ou responsável indicado',
                'satisfied' => self::mentions($content, ['contato', 'responsável', 'suporte']),
                'source'    => 'content',
            ],
        ];
    }

    /**
     * Whether the page says any of these words, ignoring capitals AND accents.
     *
     * One spelling per word in the lists above: folding both sides is what
     * removed the second entry each accented one used to carry
     * ("contingência"/"contingencia"), and with it the chance of forgetting
     * one — which is exactly how the CATI conformance checks had ended up
     * detecting "contingencia" but not "dados sensiveis".
     */
    private static function mentions(string $content, array $terms): bool
    {
        $folded = Fold::text($content);

        foreach ($terms as $term) {
            if (str_contains($folded, Fold::text($term))) {
                return true;
            }
        }

        return false;
    }
}
