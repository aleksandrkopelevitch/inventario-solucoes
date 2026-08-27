<?php

namespace App\Support\Documentation;

use App\Contracts\Documentable;
use App\Models\AttributeOption;
use App\Models\DocumentationPage;
use App\Models\Solution;
use Illuminate\Support\Str;

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
 * quality judgment), plus the drawing when the page points at one.
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
 * documentation for a checklist to sit beside — what survives of it is the one
 * item below that reads the LINKED diagram.
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

        return [...$items, ...self::diagramItems($page), ...self::contentItems($content)];
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

    /**
     * The linked drawing, and ONLY when there is one.
     *
     * Not every page documents a flow, so an always-present "tem diagrama" row
     * would report a gap on most pages that have none to have. A page that DOES
     * point at a diagram and finds it still holding only its root block is a
     * real gap, and the one this reports.
     *
     * @return list<array{key: string, label: string, satisfied: bool, source: string}>
     */
    private static function diagramItems(DocumentationPage $page): array
    {
        if ($page->diagram_id === null) {
            return [];
        }

        // `diagram_id` is checked first so this only ever loads the relation for
        // a page that actually has one.
        $nodes = $page->diagram?->chain['nodes'] ?? [];

        return [[
            'key'       => 'diagram',
            'label'     => 'Diagrama vinculado desenhado',
            'satisfied' => count($nodes) > 1,
            'source'    => 'structural',
        ]];
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
                'satisfied' => Str::contains($content, ['erro', 'falha', 'contingência', 'contingencia', 'retry', 'reprocess'], ignoreCase: true),
                'source'    => 'content',
            ],
            [
                'key'       => 'contact',
                'label'     => 'Contato ou responsável indicado',
                'satisfied' => Str::contains($content, ['contato', 'responsável', 'responsavel', 'suporte'], ignoreCase: true),
                'source'    => 'content',
            ],
        ];
    }
}
