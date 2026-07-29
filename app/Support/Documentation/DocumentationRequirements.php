<?php

namespace App\Support\Documentation;

use App\Contracts\Documentable;
use App\Models\AttributeOption;
use App\Models\DocumentationPage;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Support\Str;

/**
 * Deterministic, non-AI "minimum requirements" checklist for a documentation
 * target — surfaced as an advisory widget next to the Documentation Assistant
 * chat AND fed into its prompt, so it never needs to ask about something it
 * can already tell. Never blocks Salvar; purely informational.
 *
 * Two shapes, by target:
 * - `Integration`: mostly structural (fields already on the model) plus a
 *   couple of best-effort content checks (keyword presence in the Markdown —
 *   honest, not a quality judgment).
 * - A `DocumentationPage` whose `container` is a `Solution`: the "hosting
 *   model / category / etc." items are `source: attribute` — already known
 *   from the Solution's record, so they're reported as facts (never as gaps
 *   the user should fill in the chat), plus the same couple of content checks.
 *
 * Returns `[]` for anything else (a DocumentationPage under a standalone
 * DocumentationGroup has no Solution to pull attributes from, and the
 * Documentation Assistant isn't wired into that controller at all today) —
 * this is the "quando for o caso" (when applicable) rule.
 */
class DocumentationRequirements
{
    /** @return list<array{key: string, label: string, satisfied: bool, source: string, value?: string}> */
    public static function for(Documentable $target, ?string $content = null): array
    {
        $content = (string) ($content ?? ($target->documentation ?? ''));

        return match (true) {
            $target instanceof Integration                                                 => self::forIntegration($target, $content),
            $target instanceof DocumentationPage && $target->container instanceof Solution => self::forSolutionPage($target->container, $content),
            default                                                                        => [],
        };
    }

    /**
     * `direction`/`criticality` are NOT NULL columns on `integrations` (always
     * set), so they'd never actually flag a gap — only the genuinely optional
     * structural fields are worth checking here.
     *
     * @return list<array{key: string, label: string, satisfied: bool, source: string, value?: string}>
     */
    private static function forIntegration(Integration $integration, string $content): array
    {
        $nodes = $integration->chain['nodes'] ?? [];

        return [
            ['key' => 'protocol', 'label' => 'Protocolo definido', 'satisfied' => $integration->protocol !== null, 'source' => 'structural'],
            ['key' => 'sync_mode', 'label' => 'Modo de sincronização definido', 'satisfied' => $integration->sync_mode !== null, 'source' => 'structural'],
            ['key' => 'participants', 'label' => 'Participantes identificados', 'satisfied' => $integration->participants()->count() >= 2, 'source' => 'structural'],
            ['key' => 'diagram', 'label' => 'Diagrama do fluxo desenhado', 'satisfied' => count($nodes) > 1, 'source' => 'structural'],
            ...self::contentItems($content),
        ];
    }

    /**
     * `category`/`status`/`support_type` are NOT NULL columns on `solutions`
     * (always set), so they'd never actually flag a gap — only the genuinely
     * optional attributes are worth checking here (`environment` is the exact
     * "SaaS / on-premises / SaaS interno" example this checklist exists for).
     *
     * @return list<array{key: string, label: string, satisfied: bool, source: string, value?: string}>
     */
    private static function forSolutionPage(Solution $solution, string $content): array
    {
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

        return [...$items, ...self::contentItems($content)];
    }

    /** Best-effort, keyword-based content checks — shared by both target types. */
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
