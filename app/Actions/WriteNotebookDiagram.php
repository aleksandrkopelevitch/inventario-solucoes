<?php

namespace App\Actions;

use App\Enums\ChainNodeKind;
use App\Enums\DiagramStatus;
use App\Enums\Direction;
use App\Models\Diagram;
use App\Models\Notebook;
use App\Support\DiagramSlug;

/**
 * The one door a drawing is written through: a NEW diagram in a caderno, or a
 * new drawing over an EXISTING one of that caderno.
 *
 * There used to be four `Diagram::create()` calls repeating the same defaults
 * (the blank "Novo", the free-graph draft, the four models, the CATI approval),
 * and one of them carried its own copy of the slug loop. They all come here
 * now, which is also what makes "a diagram always belongs to a caderno" a
 * single line rather than a promise four callers each have to keep.
 *
 * **Overwriting keeps the diagram's identity** — name, slug, status, the
 * systems somebody declared by hand — and replaces only what was DRAWN. The
 * slug is what a page's `{% diagram slug="…" %}` names, so every citation of
 * it goes on working and simply shows the new drawing. The rendered picture
 * is dropped rather than kept: it is posted by the browser after a layout save
 * (`chain-viz.js`), so until somebody opens the canvas it would go on showing
 * the drawing that was just replaced, and a citation card saying "sem imagem
 * ainda" is true where an old picture is not.
 */
class WriteNotebookDiagram
{
    /**
     * @param  array{nodes: array, edges: array}|null  $chain  null = one blank root block named after the diagram
     * @param  Diagram|null  $target  the caderno's diagram to overwrite; null creates one
     */
    public function handle(Notebook $notebook, ?array $chain, ?array $layout = null, ?string $name = null, ?Diagram $target = null): Diagram
    {
        if ($target !== null) {
            // Refused here as well as in the Form Requests: an overwrite that
            // crossed cadernos would move a drawing out from under the caderno
            // whose pages cite it.
            abort_unless($target->notebook_id === $notebook->id, 422, 'Diagram belongs to another notebook.');

            // The old layout goes with the old drawing even when the new one
            // brings none: `viz_layout` is keyed by NODE INDEX, and the old
            // positions laid over a different chain would put each new block
            // where some unrelated old one used to be.
            $target->writeChain(chain: $chain ?? $this->blankChain($target->name), layout: $layout ?? []);
            $target->clearMediaCollection(Diagram::DIAGRAM_COLLECTION);
            $target->afterChainMutation();

            return $target->fresh();
        }

        $name = trim((string) $name) ?: 'Novo diagrama';

        $diagram = new Diagram([
            'name'        => $name,
            'slug'        => DiagramSlug::unique($name),
            'status'      => DiagramStatus::Planned->value,
            'criticality' => 'medium',
            'direction'   => Direction::Unidirectional->value, // re-derived from the chain right below
            'chain'       => $chain ?? $this->blankChain($name),
            'viz_layout'  => $layout,
        ]);
        $diagram->notebook()->associate($notebook);
        $diagram->save();

        $diagram->afterChainMutation();

        return $diagram;
    }

    /** What a blank canvas starts from: one free-text root block, named after the diagram. */
    private function blankChain(string $name): array
    {
        return [
            'nodes' => [['solution_id' => null, 'label' => $name, 'kind' => ChainNodeKind::System->value]],
            'edges' => [],
        ];
    }
}
