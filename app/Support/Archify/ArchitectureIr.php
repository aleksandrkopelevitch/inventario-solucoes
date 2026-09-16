<?php

namespace App\Support\Archify;

use App\Enums\ChainNodeKind;
use App\Models\Solution;
use App\Support\ChainLabeler;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A drawn canvas, as an Archify architecture spec.
 *
 * Used for ONE thing: comparing a CATI submission's AS IS with its TO BE
 * (`archify compare architecture`). It is not a second way to render a chain —
 * the canvas renders itself, and `.claude/rules/archify-artifacts.md` says why
 * `architecture` is not among the artifact types.
 *
 * **Grid placement, never free `pos`.** The canvas's own coordinates are pixels
 * a person dragged blocks to; Archify's free placement would take them, and
 * then its geometry check would refuse the pair of blocks somebody left
 * overlapping — which is normal on a working canvas and not something a
 * comparison should ever fail on. `layout.mode: "grid"` asks for a row and a
 * column instead, so the drawing's READING (what is left of what, what is
 * above what) survives while the exact pixels do not, and two blocks can never
 * collide because `assignCells()` will not put them in the same cell.
 *
 * Node identity is the chain INDEX (`n0`, `n1`, …) and that is the delta's
 * whole basis: `compare` matches components by id, so a block that kept its
 * index reads as the same component moved or relabelled, and a block that did
 * not reads as removed plus added. That is the honest answer — the canvas has
 * no stable node identity of its own (`removeNode()` reindexes), so any
 * cleverer matching would be inventing one.
 */
final class ArchitectureIr
{
    /** Archify's `componentType`, by what a block IS. */
    private const TYPE_BY_CATEGORY = [
        'data_bi'           => 'database',
        'ipaas'             => 'messagebus',
        'security'          => 'security',
        'iam'               => 'security',
        'legal_grc'         => 'security',
        'ecommerce'         => 'frontend',
        'crm'               => 'frontend',
        'customer_service'  => 'frontend',
        'erp'               => 'backend',
        'internal_platform' => 'backend',
        'itsm'              => 'backend',
        'manufacturing'     => 'cloud',
        'tms'               => 'cloud',
        'infrastructure'    => 'cloud',
        'marketing'         => 'external',
        'hcm'               => 'external',
        'payments'          => 'external',
        'tax'               => 'external',
    ];

    /**
     * @param  array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>}|null  $chain
     * @param  array<string, mixed>|null  $vizLayout
     * @param  Collection<int, Solution>  $solutions
     * @return array<string, mixed>
     */
    public static function fromCanvas(?array $chain, ?array $vizLayout, Collection $solutions, string $title): array
    {
        $nodes = array_values($chain['nodes'] ?? []);
        $positions = array_values($vizLayout['nodes'] ?? []);
        $labeler = new ChainLabeler;

        $cells = self::assignCells($nodes, $positions);

        $components = [];

        foreach ($nodes as $i => $node) {
            $kind = ChainNodeKind::fromNode($node);
            $solution = $kind->referencesSolution() ? ($solutions[$node['solution_id'] ?? null] ?? null) : null;

            $components[] = array_filter([
                'id'    => 'n' . $i,
                'type'  => self::type($kind, $solution),
                'label' => $labeler->nodeLabel($node, $solutions),
                // The sublabel is what makes a RELABEL visible in the delta
                // rather than invisible: two blocks with the same name and
                // different kinds are a real change somebody made.
                'sublabel' => $kind === ChainNodeKind::System ? null : $kind->label(),
                'row'      => $cells[$i]['row'],
                'col'      => $cells[$i]['col'],
            ], fn (mixed $value) => $value !== null);
        }

        $connections = [];

        foreach (array_values($chain['edges'] ?? []) as $i => $edge) {
            $from = $edge['from'] ?? null;
            $to = $edge['to'] ?? null;

            if (! isset($nodes[$from], $nodes[$to])) {
                continue;
            }

            $label = Str::limit(trim(($edge['protocol'] ?? '') . (($edge['arrow'] ?? '->') === '<->' ? ' ↔' : '')), 14, '…') ?: null;
            $vertical = $cells[$from]['col'] === $cells[$to]['col'];

            // A bidirectional chain edge becomes one connection carrying the
            // arrow in its label: Archify has no two-headed variant, and
            // emitting two opposite connections would read as two changes the
            // day one of them moves.
            $connections[] = array_filter([
                'id'   => 'e' . $i,
                'from' => 'n' . $from,
                'to'   => 'n' . $to,
                // Capped: the gaps below buy room for a short label, not for a
                // free-text protocol somebody typed a sentence into.
                'label' => $label,
                // A label sits at the middle of its line. Between two COLUMNS
                // that middle is the 120px gap, which is what the gap is for;
                // between two cells of the SAME column it lands on the blocks
                // themselves, and the geometry check refuses it. Pushing it
                // sideways puts it in the horizontal gap instead — the fix
                // Archify's own diagnostic suggests, applied before it has to.
                'labelDx' => $label !== null && $vertical ? 90 : null,
            ], fn (mixed $value) => $value !== null);
        }

        return [
            'schema_version' => 1,
            'diagram_type'   => 'architecture',
            'meta'           => ['title' => $title, 'visual_preset' => (string) config('services.archify.visual_preset')],
            // Wider gaps than Archify's grid defaults (30/40), because a
            // connection LABEL is painted at the midpoint between two cells and
            // the geometry check refuses one that lands on a component. At the
            // default spacing "rest" already overlapped its own source block.
            'layout' => [
                'mode' => 'grid',
                'cols' => max(1, self::columns($cells)),
                'gapX' => 120,
                'gapY' => 80,
            ],
            'components'  => $components,
            'connections' => $connections,
        ];
    }

    /**
     * Canvas pixels → grid cells, preserving the drawing's reading order.
     *
     * Columns come from the x ORDER (left to right), rows from y, and a cell
     * already taken pushes its occupant down rather than overwriting it — so
     * the output is total, never lossy, whatever the person left on the canvas.
     * A node with no saved position (added and never dragged) lands after the
     * ones that have one instead of at the origin, where it would fight with
     * the root block.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $positions
     * @return array<int, array{row: int, col: int}>
     */
    private static function assignCells(array $nodes, array $positions): array
    {
        $coords = [];

        foreach (array_keys($nodes) as $i) {
            $coords[$i] = [
                'x' => (float) ($positions[$i]['x'] ?? PHP_INT_MAX),
                'y' => (float) ($positions[$i]['y'] ?? PHP_INT_MAX),
            ];
        }

        $columns = self::ranks(array_column($coords, 'x'));
        $rows = self::ranks(array_column($coords, 'y'));

        $cells = [];
        $taken = [];

        foreach ($coords as $i => $coord) {
            $col = $columns[(string) $coord['x']];
            $row = $rows[(string) $coord['y']];

            while (isset($taken[$row . ',' . $col])) {
                $row++;
            }

            $taken[$row . ',' . $col] = true;
            $cells[$i] = ['row' => $row, 'col' => $col];
        }

        return $cells;
    }

    /**
     * Distinct values → their 0-based rank. Two blocks a few pixels apart get
     * different columns, which is correct: the canvas has no notion of a
     * column, so the only honest reading of two different x values is "this one
     * is left of that one".
     *
     * @param  list<float>  $values
     * @return array<string, int>
     */
    private static function ranks(array $values): array
    {
        $distinct = array_values(array_unique($values, SORT_NUMERIC));
        sort($distinct, SORT_NUMERIC);

        $ranks = [];

        foreach ($distinct as $rank => $value) {
            $ranks[(string) $value] = $rank;
        }

        return $ranks;
    }

    /** @param  array<int, array{row: int, col: int}>  $cells */
    private static function columns(array $cells): int
    {
        return $cells === [] ? 1 : max(array_column($cells, 'col')) + 1;
    }

    private static function type(ChainNodeKind $kind, ?Solution $solution): string
    {
        if ($kind === ChainNodeKind::Actor) {
            return 'external';
        }

        if ($kind === ChainNodeKind::Decision || $kind === ChainNodeKind::Start || $kind === ChainNodeKind::End) {
            return 'backend';
        }

        // A free-text system is something outside Leo, which is exactly what
        // the canvas already says about it with a dashed border.
        return $solution === null
            ? 'external'
            : (self::TYPE_BY_CATEGORY[$solution->category] ?? 'backend');
    }
}
