<?php

namespace App\Actions\Cati;

use App\Enums\ChainNodeKind;
use App\Enums\Direction;
use App\Models\ApprovedTopology;
use App\Models\Diagram;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes an approved TO BE onto a real Diagram — the moment the catalog
 * catches up with a committee decision.
 *
 * This is where the Fase 3 `ChainCanvas` contract pays for itself: the write
 * goes through `writeChain()` + `afterChainMutation()`, which is the same door
 * the canvas uses, so the derived columns (participants, source/target,
 * direction, the protocol summary) come out re-derived without this action
 * knowing they exist. Assigning `chain` directly here would have been the one
 * place in the app where topology changed without those following.
 *
 * A HUMAN chooses the target, always. A submission's TO BE is a free graph
 * that may describe several diagrams or one that does not exist yet, and an
 * approval that guessed would overwrite real topology with a guess — see
 * `ApprovedTopology`.
 */
class ApplyApprovedTopology
{
    /**
     * @param  Diagram|null  $target  null creates a new diagram for the drawing
     */
    public function handle(ApprovedTopology $topology, User $user, ?Diagram $target = null): Diagram
    {
        $topology->loadMissing('solution');

        $diagram = $target ?? $this->newDiagram($topology);

        // Through the contract, never by assignment: `afterChainMutation()` is
        // what re-derives participants/source/target/direction and the protocol
        // summary from the chain.
        $diagram->writeChain(chain: $topology->chain, layout: $topology->viz_layout);
        $diagram->afterChainMutation();

        // A new diagram is born with no participants at all, so the
        // solution the submission was about is attached by the sync above only
        // if the chain references it. Nothing else to do here — the chain is
        // the source of truth and it has just been written.
        $topology->update([
            'diagram_id' => $diagram->id,
            'applied_at'     => now(),
            'applied_by_id'  => $user->id,
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

    /**
     * A brand-new Diagram for a TO BE that describes something the catalog
     * does not have yet — the common case for a proposal.
     *
     * Born with an empty chain and `planned` status; the caller writes the real
     * chain immediately afterwards, which is what derives everything else. The
     * name comes from the submission so the row is recognisable in the
     * solution's list before anyone opens it.
     */
    private function newDiagram(ApprovedTopology $topology): Diagram
    {
        $topology->loadMissing('submission');

        $name = trim((string) $topology->submission?->name) ?: $topology->solution->name;

        return Diagram::create([
            'name'   => $name,
            'slug'   => $this->uniqueSlug($name),
            'status' => 'planned',
            // Both re-derived from the chain the caller is about to write; they
            // exist here only because the columns are not nullable.
            'criticality' => 'medium',
            'direction'   => Direction::Unidirectional->value,
            'chain'       => ['nodes' => [['solution_id' => null, 'label' => $name, 'kind' => ChainNodeKind::System->value]], 'edges' => []],
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'diagrama';
        $slug = $base;
        $suffix = 1;

        while (Diagram::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
