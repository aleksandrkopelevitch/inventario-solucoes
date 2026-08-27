<?php

namespace App\Http\Controllers;

use App\Enums\ChainNodeKind;
use App\Enums\DiagramStatus;
use App\Enums\Direction;
use App\Http\Controllers\Concerns\EditsChain;
use App\Http\Requests\AddChainEdgeRequest;
use App\Http\Requests\AddChainImageRequest;
use App\Http\Requests\AddChainNodeRequest;
use App\Http\Requests\RemoveChainEdgeRequest;
use App\Http\Requests\RemoveChainNodeRequest;
use App\Http\Requests\RetargetChainEdgeRequest;
use App\Http\Requests\SaveChainLayoutRequest;
use App\Http\Requests\StoreDiagramRequest;
use App\Http\Requests\UpdateChainNodeRequest;
use App\Http\Requests\UpdateChainProtocolRequest;
use App\Http\Requests\UpdateDiagramMetaRequest;
use App\Models\Diagram;
use App\Models\Solution;
use App\Services\DiagramCatalogService;
use App\View\Components\Diagrams\Index;
use App\View\Components\Diagrams\Meta;
use App\View\Components\Solutions\Diagrams as SolutionDiagrams;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Diagrams — the module. A diagram is a drawing of a flow, named and drawn on
 * its own; documentation reaches it from the other side (a page points at it),
 * which is why nothing in these URLs is scoped under a solution the way the
 * old integration routes were.
 *
 * What this controller covers is everything the canvas itself doesn't:
 * `index()` (the catalog), `store()`, `show()` (the page the canvas is mounted
 * on) and `update()` — renaming or restatusing, driven by the page's top bar
 * (`Diagrams\Meta`), one field at a time. None of those touch the chain.
 *
 * The chain mutations below are the canvas's, and their bodies live in
 * `Concerns\EditsChain` — shared with a submission's AS IS / TO BE drawings.
 * `SyncDiagramFromChain` remains the only place that derives
 * participants/source/target/direction/protocol from the chain, and it is
 * reached through `Diagram::afterChainMutation()`, never from here.
 */
class DiagramController extends Controller
{
    use EditsChain;

    public function index(Request $request, DiagramCatalogService $catalog): View|JsonResponse
    {
        $this->authorize('viewAny', Diagram::class);

        $filters = (array) $request->query('filter', []);

        if ($request->wantsJson()) {
            return response()->json([
                'updatableSlots' => [Index::slot($filters)],
            ]);
        }

        return view('diagrams.index', [
            'filters'       => $filters,
            'counters'      => $catalog->counters(),
            'statusOptions' => DiagramStatus::options(),
        ]);
    }

    /**
     * Creates a brand-new diagram. `solution_id` is optional and only seeds
     * the root block — it comes from the "Novo" form on a solution's detail
     * page, so the drawing starts from the system the person was looking at
     * instead of from an empty canvas. Created from the diagrams index there
     * is no such context, and the root is free text instead.
     *
     * Initial status is "planned", adjustable afterwards via `update()`.
     */
    public function store(StoreDiagramRequest $request): JsonResponse
    {
        $data = $request->validated();
        $solution = filled($data['solution_id'] ?? null) ? Solution::find($data['solution_id']) : null;

        $name = trim($data['name'] ?? '') ?: ($solution?->name ?? 'Novo diagrama');

        $diagram = Diagram::create([
            'name'        => $name,
            'slug'        => $this->uniqueSlug($name),
            'status'      => DiagramStatus::Planned->value,
            'criticality' => 'medium',
            'direction'   => Direction::Unidirectional->value, // re-derived from the chain right below
            'chain'       => [
                'nodes' => [[
                    'solution_id' => $solution?->id,
                    'label'       => $solution ? null : $name,
                    'kind'        => ChainNodeKind::System->value,
                ]],
                'edges' => [],
            ],
        ]);

        $diagram->afterChainMutation();

        return response()->json([
            'type'    => 'success',
            'message' => 'Diagrama criado.',
            // Straight to the canvas: a diagram that was just created is one
            // root block and nothing else, so the list it came from has
            // nothing to show about it yet, and drawing is the only sensible
            // next step.
            'redirect' => route('diagrams.show', $diagram),
        ]);
    }

    /** The canvas page — where the drawing is authored, and the only place it is. */
    public function show(Diagram $diagram): View
    {
        $this->authorize('view', $diagram);

        $diagram->load(['pages.notebook']);

        return view('diagrams.show', [
            'diagram' => $diagram,
            'title'   => $diagram->name,
        ]);
    }

    /**
     * Renames / changes the status of an existing diagram — doesn't touch the
     * chain. Called one field at a time by the canvas page's top bar
     * (`Diagrams\Meta`).
     */
    public function update(UpdateDiagramMetaRequest $request, Diagram $diagram): JsonResponse
    {
        $diagram->update($request->validated());

        return response()->json([
            'type'    => 'success',
            'message' => 'Diagrama atualizado.',
            // Both the top bar and the index list name the diagram, and the
            // index is where someone lands after leaving this page — the
            // client no-ops on whichever id isn't on the current page.
            'updatableSlots' => [Meta::slot($diagram), Index::slot()],
        ]);
    }

    /**
     * `?solution=` and `?after=` are slot/navigation context, not data: the
     * delete is offered from three screens and the response has to leave each
     * of them consistent. A solution's detail card sends its own slug so its
     * list re-renders without the row; the canvas page sends `after=index`,
     * since staying on the page of a diagram that no longer exists is a 404
     * waiting to happen. Missing on both counts (the diagrams index) is the
     * plain case — the index slot alone covers it.
     */
    public function destroy(Request $request, Diagram $diagram): JsonResponse
    {
        $this->authorize('delete', $diagram);

        // The `diagram_solution` pivot cascades on delete, and
        // `documentation_pages.diagram_id` is `nullOnDelete`: removing a
        // drawing must never take the text explaining it with it.
        $diagram->delete();

        $slots = [Index::slot()];

        if ($solution = Solution::firstWhere('slug', $request->query('solution'))) {
            $slots[] = SolutionDiagrams::slot($solution);
        }

        return response()->json(array_filter([
            'type'           => 'success',
            'message'        => 'Diagrama removido.',
            'updatableSlots' => $slots,
            'redirect'       => $request->query('after') === 'index' ? route('diagrams.index') : null,
        ], fn ($value) => $value !== null));
    }

    /*
     |--------------------------------------------------------------------------
     | Chain mutations
     |--------------------------------------------------------------------------
     |
     | The bodies live in `Concerns\EditsChain`, which performs them against
     | any `ChainCanvas`. They live there because a submission's AS IS / TO BE
     | drawings are a second owner of the same canvas: the chain's rules
     | (indices, reindexing on delete, which node is protected, what may
     | reference a Solution) are subtle enough that a second copy would
     | diverge, and the divergence would only show up as a diagram quietly
     | drawing the wrong thing.
     |
     | What stays here is the route signature and nothing else. Authorization
     | is each FormRequest's (`Concerns\AuthorizesChainOwner`), and re-deriving
     | the columns after a write is `Diagram::afterChainMutation()`, which the
     | trait calls.
     */

    public function saveLayout(SaveChainLayoutRequest $request, Diagram $diagram): JsonResponse
    {
        return $this->saveChainLayout(
            $diagram,
            $request->safe()->only(['nodes', 'edges', 'comments', 'lanes', 'notes', 'theme']),
        );
    }

    public function updateNode(UpdateChainNodeRequest $request, Diagram $diagram, int $node): JsonResponse
    {
        return $this->updateChainNode($diagram, $request->validated(), $node);
    }

    public function removeNode(RemoveChainNodeRequest $request, Diagram $diagram, int $node): JsonResponse
    {
        return $this->removeChainNode($diagram, $node);
    }

    public function updateProtocol(UpdateChainProtocolRequest $request, Diagram $diagram, int $edge): JsonResponse
    {
        return $this->updateChainProtocol($diagram, $request->validated(), $edge);
    }

    public function addNode(AddChainNodeRequest $request, Diagram $diagram): JsonResponse
    {
        return $this->addChainNode($diagram, $request->validated());
    }

    public function addImageNode(AddChainImageRequest $request, Diagram $diagram): JsonResponse
    {
        return $this->addChainImageNode($diagram, $request->file('image'));
    }

    public function retargetEdge(RetargetChainEdgeRequest $request, Diagram $diagram, int $edge): JsonResponse
    {
        return $this->retargetChainEdge($diagram, $request->validated(), $edge);
    }

    public function addEdge(AddChainEdgeRequest $request, Diagram $diagram): JsonResponse
    {
        return $this->addChainEdge($diagram, $request->validated());
    }

    public function removeEdge(RemoveChainEdgeRequest $request, Diagram $diagram, int $edge): JsonResponse
    {
        return $this->removeChainEdge($diagram, $edge);
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
