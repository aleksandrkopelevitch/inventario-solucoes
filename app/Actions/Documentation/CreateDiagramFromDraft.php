<?php

namespace App\Actions\Documentation;

use App\Actions\WriteNotebookDiagram;
use App\Models\Diagram;
use App\Models\Notebook;
use App\Models\Solution;
use App\Support\Documentation\ChainDraft;
use App\Support\Fold;

/**
 * Turns a validated draft into a real drawing.
 *
 * The split from `DiagramDraftService` is the same one `RenderSubmissionDeck`
 * keeps: whatever decides CONTENT produces JSON, the JSON is validated, and
 * only then does something else write. Nothing here can invent a block — every
 * node and edge below comes from the draft, and the only judgement it makes is
 * whether a name matches a Solution.
 *
 * The row itself is written by `WriteNotebookDiagram` — the same door a blank
 * diagram goes through, because the whole point is that what comes out is an
 * ORDINARY diagram of the caderno: a new one, or a new drawing over one the
 * author chose to replace. It is drawn, renamed,
 * rewired and deleted like any other, and the ecosystem map reads it like any
 * other — there is no "draft" state to get stuck in.
 */
class CreateDiagramFromDraft
{
    public function __construct(private readonly WriteNotebookDiagram $writer) {}

    /**
     * @param  string|null  $name  the name the author typed; the draft's own is only the fallback
     * @param  Diagram|null  $target  the caderno's diagram to draw over, instead of creating one
     */
    public function handle(ChainDraft $draft, Notebook $notebook, ?string $name = null, ?Diagram $target = null): Diagram
    {
        $solutions = $this->resolveSolutions($draft);

        $nodes = [];
        $indexOf = [];

        foreach ($draft->nodes as $node) {
            $indexOf[$node['id']] = count($nodes);

            $solution = $node['solution'] !== null
                ? ($solutions[Fold::text($node['solution'])] ?? null)
                : null;

            $nodes[] = [
                // A name that matched nothing is NOT dropped and NOT guessed at:
                // the block keeps the text as a free node, which is what the
                // canvas already means by one ("external to Leo", dashed
                // border). The alternative — a fuzzy match — is the one failure
                // here nobody would catch: `SyncDiagramFromChain` derives
                // `participants` from `solution_id`, and the ecosystem map is a
                // reading of that, so a near-miss doesn't just mislabel a block,
                // it draws a relationship in the map between two systems that
                // have none.
                'solution_id' => $solution?->id,
                // The NAME is the fallback label, not just `label` — a draft
                // that resolved a block by `solution` alone (the normal case,
                // and the one the prompt demonstrates) carries no `label` at
                // all, so a name that matched nothing produced an empty block
                // instead of a free-text one.
                'label' => $solution !== null ? null : ($node['label'] ?? $node['solution']),
                'kind'  => $node['kind'],
            ];
        }

        $edges = [];

        foreach ($draft->edges as $edge) {
            $edges[] = [
                'from'     => $indexOf[$edge['from']],
                'to'       => $indexOf[$edge['to']],
                'arrow'    => $edge['arrow'],
                'protocol' => $edge['protocol'],
            ];
        }

        return $this->writer->handle(
            $notebook,
            chain: ['nodes' => $nodes, 'edges' => $edges],
            name: trim((string) $name) ?: $draft->name,
            target: $target,
        );
    }

    /**
     * One query for every name the draft claims, matched EXACTLY once folded
     * (`whereFoldedIs`) — "Solução X" written without its accent still lands,
     * "X" alone does not. `whereFolded` would have been the wrong macro: it
     * asks "contains", so a draft naming "SAP" would match "SAP S/4HANA", "SAP
     * CPI" and "SAP ECC", and whichever row came back first would win silently.
     *
     * @return array<string, Solution> keyed by the folded name, which is what
     *                                 the caller has in hand (the model echoes
     *                                 a catalog name back, accents and all, and
     *                                 sometimes without them)
     */
    private function resolveSolutions(ChainDraft $draft): array
    {
        $names = $draft->solutionNames();

        if ($names === []) {
            return [];
        }

        $query = Solution::query()->select(['id', 'name']);

        foreach ($names as $i => $name) {
            $query->whereFoldedIs('name', $name, $i === 0 ? 'and' : 'or');
        }

        return $query->get()->keyBy(fn (Solution $solution) => Fold::text($solution->name))->all();
    }
}
