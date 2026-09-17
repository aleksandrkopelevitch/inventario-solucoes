<?php

namespace App\Support\Diagrams;

use App\Enums\ChainNodeKind;
use App\Models\Solution;
use App\Support\ChainLabeler;
use App\Support\Fold;
use Illuminate\Support\Collection;

/**
 * What one drawing changes about another — blocks and links, added and removed.
 *
 * `SubmissionDiagramKind`'s docblock says the AS IS and TO BE are drawn rather
 * than uploaded because a picture is "diffable against nothing". This is that
 * diff, and it is a pure function of the two chains: no model, no renderer, no
 * stored artifact. That last part is what makes it better than the thing it
 * replaced — a comparison computed when it is read cannot be stale, so there
 * is no "gerar de novo" button and no moment where the panel is describing two
 * drawings that have since moved on.
 *
 * IDENTITY is the whole design. Two blocks are the same block when they name
 * the same Solution, or — for free text — when their labels fold to the same
 * thing. Chain INDEX is deliberately not used: `removeNode()` reindexes, so
 * two drawings authored separately have no index in common, and matching on
 * one would report every block as both added and removed.
 */
final class TopologyDiff
{
    /**
     * @param  array<mixed>|null  $asIs
     * @param  array<mixed>|null  $toBe
     * @param  Collection<int, Solution>  $solutions
     * @return array{
     *     blocks: array{added: list<string>, removed: list<string>, kept: int},
     *     links: array{added: list<string>, removed: list<string>, kept: int},
     *     changed: bool
     * }
     */
    public static function between(?array $asIs, ?array $toBe, Collection $solutions): array
    {
        $before = self::read($asIs, $solutions);
        $after = self::read($toBe, $solutions);

        $blocksAdded = array_diff_key($after['blocks'], $before['blocks']);
        $blocksRemoved = array_diff_key($before['blocks'], $after['blocks']);
        $linksAdded = array_diff_key($after['links'], $before['links']);
        $linksRemoved = array_diff_key($before['links'], $after['links']);

        return [
            'blocks' => [
                'added'   => array_values($blocksAdded),
                'removed' => array_values($blocksRemoved),
                'kept'    => count(array_intersect_key($before['blocks'], $after['blocks'])),
            ],
            'links' => [
                'added'   => array_values($linksAdded),
                'removed' => array_values($linksRemoved),
                'kept'    => count(array_intersect_key($before['links'], $after['links'])),
            ],
            'changed' => $blocksAdded !== [] || $blocksRemoved !== [] || $linksAdded !== [] || $linksRemoved !== [],
        ];
    }

    /**
     * One chain, as two maps of identity => human label.
     *
     * @param  array<mixed>|null  $chain
     * @param  Collection<int, Solution>  $solutions
     * @return array{blocks: array<string, string>, links: array<string, string>}
     */
    private static function read(?array $chain, Collection $solutions): array
    {
        $nodes = array_values($chain['nodes'] ?? []);
        $labeler = new ChainLabeler;

        $keys = [];
        $blocks = [];

        foreach ($nodes as $i => $node) {
            $label = $labeler->nodeLabel($node, $solutions);
            $kind = ChainNodeKind::fromNode($node);
            $solutionId = $kind->referencesSolution() ? ($node['solution_id'] ?? null) : null;

            // A registered Solution is the same block under any label somebody
            // typed over it; free text is the same block when it reads the same.
            $keys[$i] = $solutionId !== null ? 'solution:' . $solutionId : 'label:' . Fold::text($label);
            $blocks[$keys[$i]] = $label;
        }

        $links = [];

        foreach (array_values($chain['edges'] ?? []) as $edge) {
            $from = $keys[$edge['from'] ?? null] ?? null;
            $to = $keys[$edge['to'] ?? null] ?? null;

            if ($from === null || $to === null) {
                continue;
            }

            // A link is identified by the PAIR it joins, not by its direction or
            // its protocol: turning an arrow around or naming its protocol is a
            // change to a link that already existed, and reporting it as one
            // removal plus one addition would drown the blocks that really came
            // and went. The label still says which way it points.
            $pair = [$from, $to];
            sort($pair);

            $arrow = $edge['arrow'] ?? '->';
            $names = [$blocks[$from], $blocks[$to]];

            $links[implode('~', $pair)] = $arrow === '<-'
                ? $names[1] . ' → ' . $names[0]
                : $names[0] . ($arrow === '<->' ? ' ↔ ' : ' → ') . $names[1];
        }

        return ['blocks' => $blocks, 'links' => $links];
    }
}
