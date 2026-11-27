<?php

namespace App\Services;

use App\Models\AttributeOption;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Support\CategoryPalette;
use App\Support\Documentation\UmlDiagram;
use App\Support\Images\PublicImage;

/**
 * The ecosystem map's payload (`/map`), in a NEUTRAL (renderer-agnostic)
 * contract — the canvas schema is the client's business:
 *
 *     [
 *         'nodes' => [ ['id','label','slug','category','logo','url',
 *             'categoryLabel','statusLabel','criticalityLabel','environmentLabel',
 *             'cloudLabel','contractLabel','supportLabel','directorate',
 *             'categoryFamily','hosting'], ... ],
 *         'edges' => [ ['id','source','target','label','direction','evidence','manual'], ... ],
 *         'hostings' => [ ['id','label','kind','color','image','icon'], ... ],
 *     ]
 *
 * One arrow per PAIR of systems, at solution level. Since 2026-11-26 the
 * arrows come from the documentation — SolutionRelationshipService's DIRECT
 * pairs, as the UML diagrams in caderno pages state them — and no longer from
 * the topology drawings, which were removed
 * (docs-and-specs/solution-relationships-from-uml.md). `direction` is
 * `bidirectional` (a head at each end), `unidirectional` (source → target) or
 * `undirected` (no head: a plain association). A pair inferred THROUGH a
 * platform is not drawn: it would only repeat the two arrows that already
 * meet at the platform.
 *
 * `hosting` places a system in the "Por hospedagem" view: see `hostingOf()`.
 */
class EcosystemMapService
{
    /** The container for a system with no cloud and no hosting model. */
    public const UNKNOWN_HOSTING = 'unknown';

    /** What a block on the map needs from a solution — and nothing more. */
    private const SOLUTION_COLUMNS = [
        'id', 'name', 'slug', 'category', 'logo_path', 'status', 'criticality',
        'environment', 'cloud', 'contract_status', 'support_type', 'directorate',
    ];

    public function __construct(private readonly SolutionRelationshipService $relationships) {}

    /**
     * The whole ecosystem. `$wholeCatalog` adds every solution, related or
     * not: the "Por hospedagem" view is a picture of where EVERYTHING runs,
     * while the links view only has something to say about systems that talk
     * to another one.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, hostings: list<array<string, mixed>>}
     */
    public function globalMap(bool $wholeCatalog = false): array
    {
        $pairs = $this->relationships->pairs();

        $ids = array_unique(array_merge([], ...array_map(
            fn (array $pair) => [$pair['source']->getKey(), $pair['target']->getKey()],
            $pairs,
        )));

        $solutions = Solution::query()
            ->select(self::SOLUTION_COLUMNS)
            ->when(! $wholeCatalog, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get();

        $nodes = $solutions->map(fn (Solution $solution) => $this->solutionNode($solution))->values()->all();

        $edges = array_map(fn (array $pair) => [
            'id'        => 'pair-' . $pair['source']->getKey() . '-' . $pair['target']->getKey(),
            'source'    => 'sol-' . $pair['source']->getKey(),
            'target'    => 'sol-' . $pair['target']->getKey(),
            'label'     => implode(' · ', $pair['labels']),
            'direction' => $pair['direction'],
            'evidence'  => array_map(fn (array $e) => [
                'name' => $this->evidenceName($e['page'], $e['title'], $e['type']),
                'url'  => route('notebooks.pages.edit', [$e['page']->notebook, $e['page']]) . '#uml-figure-' . $e['position'],
            ], $pair['evidence']),
            // A person recorded (or corrected) this pair on the solution page.
            'manual' => $pair['manual'],
        ], $pairs);

        return [
            'nodes'    => $nodes,
            'edges'    => $edges,
            'hostings' => $this->hostings($nodes),
        ];
    }

    private function evidenceName(DocumentationPage $page, ?string $title, string $type): string
    {
        return $page->notebook->name . ' › ' . $page->title . ' — ' . ($title ?? UmlDiagram::TYPES[$type] ?? 'Diagrama UML');
    }

    /**
     * One solution as the map's renderer expects it — the same block in both
     * views, so a system never changes shape when the view does. It carries
     * the same 8 attributes `Solutions\DetailHeader` shows, so the map's card
     * needs no round trip per click; `categoryFamily` is the colour family
     * `CategoryPalette` assigns everywhere else (the canvas reads the
     * `--color-cat-*` token, so the palette is defined once, in `@theme`).
     *
     * @return array<string, mixed>
     */
    private function solutionNode(Solution $solution): array
    {
        return [
            'id'               => "sol-{$solution->id}",
            'label'            => $solution->name,
            'slug'             => $solution->slug,
            'category'         => $solution->category,
            'logo'             => PublicImage::url($solution->logo_path),
            'url'              => route('solutions.show', $solution),
            'categoryLabel'    => $solution->category_label,
            'statusLabel'      => $solution->status_label,
            'criticalityLabel' => $solution->criticality_label,
            'environmentLabel' => $solution->environment_label,
            'cloudLabel'       => $solution->cloud_label,
            'contractLabel'    => $solution->contract_status_label,
            'supportLabel'     => $solution->support_type_label,
            'directorate'      => $solution->directorate,
            'categoryFamily'   => CategoryPalette::family($solution->category),
            'hosting'          => $this->hostingOf($solution),
        ];
    }

    /**
     * Which container a system sits in on the "Por hospedagem" view.
     *
     * The CLOUD wins when there is one — "SaaS on AWS" runs on AWS, and that is
     * the fact the picture is about — and the hosting model (SaaS, On-Premises…)
     * answers for everything else. A system with neither lands in the "Não
     * informado" container rather than nowhere, so the view still accounts for
     * the whole catalog and shows what is left to fill in.
     */
    public function hostingOf(Solution $solution): string
    {
        return match (true) {
            filled($solution->cloud)       => "cloud:{$solution->cloud}",
            filled($solution->environment) => "environment:{$solution->environment}",
            default                        => self::UNKNOWN_HOSTING,
        };
    }

    /**
     * The containers the given nodes fall into, with the look the attribute
     * screen gives them (colour, picture, icon). Clouds first, then hosting
     * models, then "Não informado" — the order they are laid out in.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function hostings(array $nodes): array
    {
        $used = array_unique(array_column($nodes, 'hosting'));
        $containers = [];

        foreach (['cloud', 'environment'] as $group) {
            foreach (AttributeOption::options($group) as $option) {
                $id = "{$group}:{$option->value}";
                if (! in_array($id, $used, true)) {
                    continue;
                }
                $containers[] = [
                    'id'    => $id,
                    'label' => $option->label,
                    'kind'  => $group,
                    'color' => $option->color ?? AttributeOption::DEFAULT_COLOR,
                    'image' => $option->imageUrl(),
                    'icon'  => $option->icon,
                ];
                $used = array_diff($used, [$id]);
            }
        }

        // Whatever is left is unknown, or a value whose option was deleted —
        // both are "we do not know where this runs".
        if ($used !== []) {
            $containers[] = [
                'id'    => self::UNKNOWN_HOSTING, 'label' => 'Não informado', 'kind' => null,
                'color' => AttributeOption::DEFAULT_COLOR, 'image' => null, 'icon' => null,
            ];
        }

        return $containers;
    }
}
