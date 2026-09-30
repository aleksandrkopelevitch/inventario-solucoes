<?php

namespace App\Actions\Cati;

use App\Actions\WriteNotebookDiagram;
use App\Models\ApprovedTopology;
use App\Models\Diagram;
use App\Models\Notebook;
use App\Models\User;

/**
 * Writes an approved TO BE onto a real Diagram — the moment the catalog
 * catches up with a committee decision.
 *
 * The write goes through `WriteNotebookDiagram`, the door every drawing is
 * written through: `writeChain()` + `afterChainMutation()`, so the derived
 * columns (participants, source/target, direction, the protocol summary) come
 * out re-derived without this action knowing they exist, and a new diagram
 * lands in the caderno the person picked — a diagram belongs to a caderno,
 * and a proposal's TO BE is no exception.
 *
 * A HUMAN chooses the target, always. A submission's TO BE is a free graph
 * that may describe several diagrams or one that does not exist yet, and an
 * approval that guessed would overwrite real topology with a guess — see
 * `ApprovedTopology`.
 */
class ApplyApprovedTopology
{
    public function __construct(private readonly WriteNotebookDiagram $writer) {}

    /**
     * @param  Diagram|null  $target  null creates a new diagram for the drawing
     * @param  Notebook|null  $notebook  where that new diagram goes; required when `$target` is null
     */
    public function handle(ApprovedTopology $topology, User $user, ?Diagram $target = null, ?Notebook $notebook = null): Diagram
    {
        $topology->loadMissing(['solution', 'submission']);

        if ($target !== null) {
            $target->loadMissing('notebook');
            $notebook = $target->notebook;
        }

        throw_if($notebook === null, \InvalidArgumentException::class, 'A new diagram needs a notebook.');

        // Named after the submission, so the row is recognisable in the
        // caderno and on the solution's page before anyone opens it. An
        // overwrite keeps the target's own name.
        $name = trim((string) $topology->submission?->name) ?: $topology->solution->name;

        $diagram = $this->writer->handle(
            $notebook,
            chain: $topology->chain,
            layout: $topology->viz_layout,
            name: $name,
            target: $target,
        );

        $topology->update([
            'diagram_id'    => $diagram->id,
            'applied_at'    => now(),
            'applied_by_id' => $user->id,
        ]);

        return $diagram->fresh();
    }

    /** Marks the catalog as already correct, without touching any topology. */
    public function dismiss(ApprovedTopology $topology, User $user, ?string $reason = null): void
    {
        $topology->update([
            'dismissed_at'     => now(),
            'dismissed_by_id'  => $user->id,
            'dismissed_reason' => $reason,
        ]);
    }
}
