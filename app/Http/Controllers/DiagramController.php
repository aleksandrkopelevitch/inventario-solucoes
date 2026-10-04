<?php

namespace App\Http\Controllers;

use App\Actions\SetDiagramSystems;
use App\Enums\DiagramStatus;
use App\Http\Controllers\Concerns\EditsChain;
use App\Http\Requests\AddChainEdgeRequest;
use App\Http\Requests\AddChainImageRequest;
use App\Http\Requests\AddChainNodeRequest;
use App\Http\Requests\RemoveChainEdgeRequest;
use App\Http\Requests\RemoveChainNodeImageRequest;
use App\Http\Requests\RemoveChainNodeRequest;
use App\Http\Requests\RetargetChainEdgeRequest;
use App\Http\Requests\SaveChainLayoutRequest;
use App\Http\Requests\SetChainNodeImageRequest;
use App\Http\Requests\SyncDiagramSystemsRequest;
use App\Http\Requests\UpdateChainNodeRequest;
use App\Http\Requests\UpdateChainProtocolRequest;
use App\Http\Requests\UpdateDiagramMetaRequest;
use App\Models\Diagram;
use App\Models\Notebook;
use App\Models\Solution;
use App\Services\DiagramCatalogService;
use App\View\Components\Diagrams\Index;
use App\View\Components\Diagrams\Meta;
use App\View\Components\Diagrams\Systems;
use App\View\Components\Solutions\Diagrams as SolutionDiagrams;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Diagrams — the module. A diagram is a drawing of a flow that belongs to a
 * caderno; prose reaches it by citing it. It is still addressed by itself, so
 * nothing in these URLs is scoped under a solution or a caderno.
 *
 * What this controller covers is everything the canvas itself doesn't:
 * `index()` (the catalog, across cadernos), `show()` (the page the canvas is
 * mounted on) and `update()` — renaming or restatusing, driven by the page's
 * top bar (`Diagrams\Meta`), one field at a time. None of those touch the
 * chain. There is no `store()`: a diagram is only ever created inside a
 * caderno (`NotebookDiagramController`, `NotebookPageDiagramController`).
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
            // Only the cadernos that HAVE a drawing: a filter option that
            // always answers "nenhum" is a question nobody needs to ask.
            'notebookOptions' => Notebook::query()->whereHas('diagrams')->orderBy('name')->get(['name', 'slug'])
                ->map(fn (Notebook $notebook) => ['value' => $notebook->slug, 'label' => $notebook->name])
                ->all(),
        ]);
    }

    /** The canvas page — where the drawing is authored, and the only place it is. */
    public function show(Diagram $diagram): View
    {
        $this->authorize('view', $diagram);

        // The top bar leads back to it — loaded here, since a single-row
        // fetch never arms strict mode's lazy-loading guard.
        $diagram->load('notebook:id,name,slug');

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
     * The systems somebody declares this drawing concerns — the MANUAL half of
     * `diagram_solution` (`SetDiagramSystems`), never the half derived from the
     * chain.
     *
     * It is here rather than on the canvas's chain endpoints because it is not
     * a chain edit: nothing about the drawing changes, only what the catalog
     * and the ecosystem map are told it is about. A drawing whose systems are
     * lanes and neutral steps — every generated process and data flow — had no
     * way to say so at all before this.
     */
    public function syncSystems(SyncDiagramSystemsRequest $request, Diagram $diagram, SetDiagramSystems $systems): JsonResponse
    {
        // BEFORE and after, unioned, for the same reason `NotebookController::
        // syncSolutions()` does it: a system that was just UNDECLARED has to
        // stop listing this drawing on its own page.
        $affected = $diagram->participants()->pluck('solutions.id');

        $systems->handle($diagram, $request->solutionIds());

        $affected = $affected->merge($diagram->participants()->pluck('solutions.id'))->unique();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Sistemas do diagrama salvos.',
            'updatableSlots' => [
                Systems::slot($diagram),
                // The index row names the systems a drawing touches, and each
                // affected solution's card lists the drawings it appears in.
                Index::slot(),
                ...Solution::whereKey($affected)->get()->map(fn (Solution $solution) => SolutionDiagrams::slot($solution)),
            ],
        ]);
    }

    /**
     * The diagram catalog as a tree, for the documentation editor's `diagram`
     * block: solutions on top, their drawings underneath.
     *
     * Led by "Deste caderno" when the editor says which caderno it is writing
     * in — the drawings that belong to it, the likeliest thing to cite. Not
     * SCOPED to it: citing another caderno's drawing is legitimate, and the
     * public route authorises a picture by citation, not by owner.
     *
     * Then grouped by SOLUTION, because it is how someone writing a page thinks — "the drawing about
     * SAP". `participants` is derived from each chain, so a drawing appears
     * under every system it actually touches, which is the same answer the
     * ecosystem map gives.
     *
     * A drawing that names no catalog solution is not hidden: it lands in a
     * trailing group of its own. The picker's job is to let a citation be
     * written, and a diagram nobody could reach from here would simply be
     * uncitable.
     *
     * Each entry carries its `pictureUrl` (null when the canvas has never been
     * saved with one) and its `url`, because the editor's block PREVIEWS the
     * citation — it draws the same card the reader will get, and this is the
     * one payload it already fetches. Both are built with `route()` here rather
     * than assembled in JS, which is what keeps `docs-tools/diagram.js` free of
     * a path of its own. The media is eager-loaded: `picture()` is a
     * `getFirstMedia()` per diagram, which would be a query per row on a
     * catalog meant to be one.
     */
    public function catalog(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Diagram::class);

        $diagrams = Diagram::query()
            ->with(['participants:id,name', 'media', 'notebook:id,slug'])
            ->orderBy('name')
            ->get(['id', 'notebook_id', 'name', 'slug']);

        $groups = [];
        // The caderno the editor is writing in (`?notebook=`), whose own
        // drawings are the likeliest citation and so come FIRST, as a group of
        // their own — also listed under their systems below, like any other.
        $here = (string) $request->query('notebook');
        $own = [];

        foreach ($diagrams as $diagram) {
            $entry = [
                'slug'       => $diagram->slug,
                'name'       => $diagram->name,
                'pictureUrl' => $diagram->picture() ? route('diagrams.picture.show', $diagram) : null,
                'url'        => route('diagrams.show', $diagram),
            ];

            if ($here !== '' && $diagram->notebook->slug === $here) {
                $own[] = $entry;
            }

            if ($diagram->participants->isEmpty()) {
                $groups['__loose']['diagrams'][] = $entry;

                continue;
            }

            foreach ($diagram->participants as $solution) {
                $groups[$solution->name]['diagrams'][] = $entry;
            }
        }

        ksort($groups);

        // The catch-all goes last whatever it sorts as.
        $loose = $groups['__loose'] ?? null;
        unset($groups['__loose']);

        $tree = collect($groups)
            ->map(fn (array $group, string $name) => ['solution' => $name, 'diagrams' => $group['diagrams']])
            ->values()
            ->all();

        if ($loose) {
            $tree[] = ['solution' => 'Sem solução no catálogo', 'diagrams' => $loose['diagrams']];
        }

        if ($own !== []) {
            array_unshift($tree, ['solution' => 'Deste caderno', 'diagrams' => $own]);
        }

        return response()->json(['groups' => $tree]);
    }

    /**
     * `?solution=` and `?after=` are slot/navigation context, not data: the
     * delete is offered from three screens and the response has to leave each
     * of them consistent. A solution's detail card sends its own slug so its
     * list re-renders without the row; the canvas page sends `after=notebook`,
     * since staying on the page of a deleted diagram is a 404 waiting to
     * happen, and the caderno is where the drawing was opened from. Missing on
     * both counts (the diagrams index) is the plain case — the index slot
     * alone covers it.
     */
    public function destroy(Request $request, Diagram $diagram): JsonResponse
    {
        $this->authorize('delete', $diagram);

        // The `diagram_solution` pivot cascades on delete. Nothing in
        // documentation does: a page CITES a drawing in its text, so deleting
        // one leaves a citation pointing at a diagram that is gone — rendered
        // as a plain "diagrama removido" card rather than taking the prose with
        // it (see GitbookRenderer::renderDiagram).
        $diagram->delete();

        $slots = [Index::slot()];

        if ($solution = Solution::firstWhere('slug', $request->query('solution'))) {
            $slots[] = SolutionDiagrams::slot($solution);
        }

        return response()->json(array_filter([
            'type'           => 'success',
            'message'        => 'Diagrama removido.',
            'updatableSlots' => $slots,
            'redirect'       => $request->query('after') === 'notebook' ? route('notebooks.show', $diagram->notebook) : null,
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

    public function setNodeImage(SetChainNodeImageRequest $request, Diagram $diagram, int $node): JsonResponse
    {
        return $this->setChainNodeImage($diagram, $request->file('image'), $node);
    }

    public function removeNodeImage(RemoveChainNodeImageRequest $request, Diagram $diagram, int $node): JsonResponse
    {
        return $this->removeChainNodeImage($diagram, $node);
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
}
