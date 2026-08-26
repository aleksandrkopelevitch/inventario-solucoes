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
 * Only one shape now: a `DocumentationPage` whose `container` is a `Solution`.
 * The "hosting model / criticality / directorate" items are `source:
 * attribute` — already known from the Solution's record, so they're reported as
 * facts, never as gaps the user should fill in the chat — plus a few
 * best-effort content checks (keyword presence in the Markdown: honest, not a
 * quality judgment), plus the drawing when the page points at one.
 *
 * There used to be a second shape for an `Integration`, checking its
 * protocol/sync mode/participants and whether its chain had anything drawn on
 * it. Those are a diagram's properties, and a diagram no longer carries
 * documentation for a checklist to sit beside — what survives of it is the one
 * item below that reads the LINKED diagram.
 *
 * Returns `[]` for anything else (a page under a standalone
 * DocumentationGroup has no Solution to pull attributes from) — this is the
 * "quando for o caso" (when applicable) rule.
 */
class DocumentationRequirements
{
    /** @return list<array{key: string, label: string, satisfied: bool, source: string, value?: string}> */
    public static function for(Documentable $target, ?string $content = null): array
    {
        $content = (string) ($content ?? ($target->documentation ?? ''));

        return $target instanceof DocumentationPage && $target->container instanceof Solution
            ? self::forSolutionPage($target, $content)
            : [];
    }

    /**
     * `category`/`status`/`support_type` are NOT NULL columns on `solutions`
     * (always set), so they'd never actually flag a gap — only the genuinely
     * optional attributes are worth checking here (`environment` is the exact
     * "SaaS / on-premises / SaaS interno" example this checklist exists for).
     *
     * @return list<array{key: string, label: string, satisfied: bool, source: string, value?: string}>
     */
    private static function forSolutionPage(DocumentationPage $page, string $content): array
    {
        $solution = $page->container;

        $attributes = [
            ['key' => 'environment', 'label' => 'Hospedagem', 'group' => 'environment', 'value' => $solution->environment],
            ['key' => 'criticality', 'label' => 'Criticidade', 'group' => 'criticality', 'value' => $solution->criticality],
            ['key' => 'directorate', 'label' => 'Diretoria responsável', 'group' => 'directorate', 'value' => $solution->directorate],
        ];

        $items = array_map(fn (array $attr) => [
            'key'       => $attr['key'],
            'label'     => $attr['label'],
            'satisfied' => $attr['value'] !== null,
            'source'    => 'attribute',
            'value'     => AttributeOption::labelFor($attr['group'], $attr['value']),
        ], $attributes);

        return [...$items, ...self::diagramItems($page), ...self::contentItems($content)];
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
