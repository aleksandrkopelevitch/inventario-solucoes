<?php

namespace App\Support\Diagrams;

use App\Enums\ChainNodeKind;
use App\Enums\Protocol;
use App\Models\Diagram;
use App\Models\Solution;
use Illuminate\Support\Str;

/**
 * Turns one topology drawing (`Diagram`, the F3 canvas) into a `{% uml %}`
 * block — the one-shot conversion of phase 3 of
 * docs-and-specs/solution-relationships-from-uml.md, deleted with `Diagram`
 * in phase 4.
 *
 * What it keeps is what the relationships need: every block that is a
 * catalog system carries `"solution": "<slug>"`, and every link keeps its
 * direction (`->` from→to, `<-` to→from, `<->` both ways) and its protocol
 * as the label. The TYPE follows what the drawing is, read from its blocks
 * (there is no model column to ask):
 *
 * - **sequence** when it has lifelines, or when it is systems talking in
 *   rounds — only systems and actors, with two or more links between one pair
 *   (the e-commerce overview: SAP → CPI → Digibee → VTEX and back, three
 *   times). As a structural diagram those rounds would be lines drawn on top
 *   of each other; as a sequence they read in order.
 * - **activity** when no block is a system: a process of steps and decisions.
 * - **component** otherwise.
 *
 * Cells come from the canvas positions (bucketed into columns and rows), or,
 * for a drawing never laid out, from the links' depth. They are a starting
 * point the person adjusts in the editor; what this must get exactly right is
 * that no two elements share a cell, which the validator refuses.
 */
final class DiagramToUml
{
    /** Two canvas x positions closer than this are one column; y, one row. */
    private const COLUMN_GAP = 110;

    private const ROW_GAP = 70;

    private const MAX_LABEL = 80;

    /**
     * @return array{type: string, title: string, body: string, bindings: int}
     */
    public function convert(Diagram $diagram): array
    {
        $nodes = array_values($diagram->chain['nodes'] ?? []);
        $edges = array_values(array_filter(
            $diagram->chain['edges'] ?? [],
            fn ($edge) => is_array($edge) && isset($nodes[$edge['from'] ?? -1], $nodes[$edge['to'] ?? -1]),
        ));

        $solutions = Solution::query()
            ->whereIn('id', array_filter(array_column($nodes, 'solution_id')))
            ->get(['id', 'slug', 'name'])
            ->keyBy('id');

        $kinds = array_map(fn (array $node) => ChainNodeKind::fromNode($node), $nodes);
        $solutionOf = fn (int $i): ?Solution => $solutions->get($nodes[$i]['solution_id'] ?? 0);
        $slug = fn (int $i): ?string => $kinds[$i]->referencesSolution() ? $solutionOf($i)?->slug : null;
        $label = fn (int $i): string => Str::limit(trim((string) ($nodes[$i]['label'] ?? ''))
            ?: ($solutionOf($i)?->name ?? $kinds[$i]->label()), self::MAX_LABEL - 3);

        $type = $this->typeFor($kinds, $edges);
        $body = $type === 'sequence'
            ? $this->sequence($nodes, $edges, $kinds, $slug, $label)
            : $this->grid($diagram, $nodes, $edges, $kinds, $slug, $label, $type);

        $bindings = count(array_filter(array_keys($nodes), fn (int $i) => $slug($i) !== null));

        return [
            'type'     => $type,
            'title'    => Str::limit($diagram->name, 117),
            'body'     => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'bindings' => $bindings,
        ];
    }

    /**
     * @param  list<ChainNodeKind>  $kinds
     * @param  list<array<string, mixed>>  $edges
     */
    private function typeFor(array $kinds, array $edges): string
    {
        if (in_array(ChainNodeKind::Lifeline, $kinds, true)) {
            return 'sequence';
        }

        $systems = array_filter($kinds, fn (ChainNodeKind $k) => $k->referencesSolution());

        if ($systems === []) {
            return 'activity';
        }

        $onlyParticipants = array_filter($kinds, fn (ChainNodeKind $k) => ! in_array($k, [ChainNodeKind::System, ChainNodeKind::Actor], true)) === [];
        $pairs = array_count_values(array_map(fn (array $e) => min($e['from'], $e['to']) . '|' . max($e['from'], $e['to']), $edges));

        return $onlyParticipants && count($kinds) <= 12 && $pairs !== [] && max($pairs) > 1 && count($edges) <= 60
            ? 'sequence'
            : 'component';
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  list<ChainNodeKind>  $kinds
     * @return array<string, mixed>
     */
    private function sequence(array $nodes, array $edges, array $kinds, \Closure $slug, \Closure $label): array
    {
        $participants = [];

        foreach (array_keys($nodes) as $i) {
            $participants[] = array_filter([
                'id'    => "n{$i}",
                'label' => $label($i),
                'kind'  => match (true) {
                    $kinds[$i]->referencesSolution()    => 'component',
                    $kinds[$i] === ChainNodeKind::Actor => 'actor',
                    default                             => 'object',
                },
                'solution' => $slug($i),
            ], fn ($value) => $value !== null);
        }

        $messages = [];

        foreach ($edges as $edge) {
            foreach ($this->directions($edge) as [$from, $to]) {
                $messages[] = array_filter(['from' => "n{$from}", 'to' => "n{$to}", 'label' => $this->protocol($edge)], fn ($v) => $v !== null);
            }
        }

        return ['numbered' => true, 'participants' => $participants, 'messages' => $messages];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @param  list<ChainNodeKind>  $kinds
     * @return array<string, mixed>
     */
    private function grid(Diagram $diagram, array $nodes, array $edges, array $kinds, \Closure $slug, \Closure $label, string $type): array
    {
        $cells = $this->cells($diagram, $nodes, $edges, vertical: $type === 'activity');
        $out = [];

        foreach (array_keys($nodes) as $i) {
            $kind = match ($kinds[$i]) {
                ChainNodeKind::System, ChainNodeKind::Lifeline => 'component',
                ChainNodeKind::Actor                           => 'actor',
                ChainNodeKind::Decision                        => 'decision',
                ChainNodeKind::Start                           => 'initial',
                ChainNodeKind::End                             => 'final',
                ChainNodeKind::Image                           => 'note',
                default                                        => 'action',
            };

            $out[] = array_filter([
                'id'       => "n{$i}",
                'kind'     => $kind,
                'name'     => in_array($kind, ['initial', 'final', 'note'], true) ? null : $label($i),
                'text'     => $kind === 'note' ? 'Imagem do diagrama original' : null,
                'solution' => $slug($i),
                'at'       => $cells[$i],
            ], fn ($value) => $value !== null);
        }

        $links = [];

        foreach ($edges as $edge) {
            foreach ($this->directions($edge) as [$from, $to]) {
                $links[] = array_filter([
                    'from'  => "n{$from}",
                    'to'    => "n{$to}",
                    'kind'  => $type === 'activity' ? 'flow' : 'directed',
                    'label' => $this->protocol($edge),
                ], fn ($v) => $v !== null);
            }
        }

        return ['nodes' => $out, 'edges' => $links];
    }

    /**
     * Which way(s) one canvas link runs, as [from, to] node indexes.
     *
     * @param  array<string, mixed>  $edge
     * @return list<array{0: int, 1: int}>
     */
    private function directions(array $edge): array
    {
        return match ($edge['arrow'] ?? '->') {
            '<-'    => [[$edge['to'], $edge['from']]],
            '<->'   => [[$edge['from'], $edge['to']], [$edge['to'], $edge['from']]],
            default => [[$edge['from'], $edge['to']]],
        };
    }

    /** @param  array<string, mixed>  $edge */
    private function protocol(array $edge): ?string
    {
        $value = trim((string) ($edge['protocol'] ?? ''));

        return $value === '' ? null : Str::limit(Protocol::tryFrom($value)?->label() ?? $value, self::MAX_LABEL - 3);
    }

    /**
     * One distinct cell per block. From the canvas positions when every block
     * has one and they fit the grid; otherwise from the links, each block one
     * step past the deepest block linking to it — a step DOWN in an activity
     * diagram (a process reads top to bottom, and fourteen steps do not fit
     * twelve columns), a step RIGHT in the others.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array<int, array{0: int, 1: int}>
     */
    private function cells(Diagram $diagram, array $nodes, array $edges, bool $vertical): array
    {
        $positions = $diagram->viz_layout['nodes'] ?? [];
        $placed = array_keys($nodes) !== [] && array_filter(array_keys($nodes), fn (int $i) => ! isset($positions[$i]['x'], $positions[$i]['y'])) === [];

        $wanted = ($placed ? $this->fromCanvas($nodes, $positions) : null) ?? $this->fromLinks($nodes, $edges, $vertical);

        // Clamp to the grid and resolve collisions: the next free cell on the
        // same row, else the first free cell below.
        $taken = [];
        $cells = [];

        foreach ($wanted as $i => [$col, $row]) {
            $col = max(0, min(11, $col));
            $row = max(0, min(19, $row));

            while (isset($taken["{$col},{$row}"])) {
                $col++;

                if ($col > 11) {
                    $col = 0;
                    $row = min(19, $row + 1);
                }
            }

            $taken["{$col},{$row}"] = true;
            $cells[$i] = [$col, $row];
        }

        return $cells;
    }

    /**
     * The canvas positions as cells, or null when they need more columns or
     * rows than the grid has — squeezed in, they would pile onto its edge.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<int|string, array<string, mixed>>  $positions
     * @return array<int, array{0: int, 1: int}>|null
     */
    private function fromCanvas(array $nodes, array $positions): ?array
    {
        $rank = function (array $values, int $gap): array {
            sort($values);
            $ranks = [];
            $index = -1;
            $last = null;

            foreach ($values as $value) {
                if ($last === null || $value - $last >= $gap) {
                    $index++;
                }
                $ranks[(string) $value] = $index;
                $last = $value;
            }

            return $ranks;
        };

        $xs = $rank(array_map(fn (int $i) => (float) $positions[$i]['x'], array_keys($nodes)), self::COLUMN_GAP);
        $ys = $rank(array_map(fn (int $i) => (float) $positions[$i]['y'], array_keys($nodes)), self::ROW_GAP);

        if (max($xs) > 11 || max($ys) > 19) {
            return null;
        }

        $cells = [];
        foreach (array_keys($nodes) as $i) {
            $cells[$i] = [$xs[(string) (float) $positions[$i]['x']], $ys[(string) (float) $positions[$i]['y']]];
        }

        return $cells;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array<int, array{0: int, 1: int}>
     */
    private function fromLinks(array $nodes, array $edges, bool $vertical): array
    {
        $depth = array_fill_keys(array_keys($nodes), 0);

        // Longest path, bounded by the node count so a cycle cannot loop.
        for ($pass = 0; $pass < count($nodes); $pass++) {
            $changed = false;
            foreach ($edges as $edge) {
                foreach ($this->directions($edge) as [$from, $to]) {
                    if ($from !== $to && $depth[$to] < $depth[$from] + 1 && count($nodes) > $depth[$from] + 1) {
                        $depth[$to] = $depth[$from] + 1;
                        $changed = true;
                    }
                }
            }
            if (! $changed) {
                break;
            }
        }

        $besides = [];
        $cells = [];
        foreach (array_keys($nodes) as $i) {
            $step = $depth[$i];
            $besides[$step] = ($besides[$step] ?? -1) + 1;

            // Past the grid's length in the flow's direction, wrap onto a
            // band further across.
            $cells[$i] = $vertical
                ? [$besides[$step] + intdiv($step, 20) * 4, $step % 20]
                : [$step % 12, $besides[$step] + intdiv($step, 12) * 4];
        }

        return $cells;
    }
}
