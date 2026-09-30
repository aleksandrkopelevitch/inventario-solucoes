<?php

namespace App\Http\Controllers;

use App\Actions\Documentation\CreateDiagramFromDraft;
use App\Actions\Documentation\CreateDiagramFromModel;
use App\Enums\DiagramModel;
use App\Http\Requests\DrawNotebookPageRequest;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\Documentation\DiagramDraftService;
use App\Services\Documentation\DiagramModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Desenhar esta página" — one drawing, proposed from a page's prose, written
 * into the page's CADERNO.
 *
 * Two steps. `target()` is the dialog that opens on the click: create a new
 * diagram of this caderno under a name the author types, or draw over one the
 * caderno already has. Only then do `store()` / `model()` run the model call —
 * synchronous, up to a minute — and write the answer through
 * `WriteNotebookDiagram`. Asking before rather than after is what keeps the
 * call a single request with nothing parked in between.
 *
 * The drawing belongs to the caderno, not to the page: a page is READ here and
 * never written, and reaches the drawing by citing it (`{% diagram %}`). The
 * `documentation_pages.diagram_id` FK that once said "this page's drawing" is
 * not coming back — see `.claude/rules/page-to-diagram-draft.md`.
 *
 * The result opens in a NEW TAB (`data-ak-ajax-target="_blank"` on the
 * dialog's confirm button), so the page stays exactly as it was, unsaved edits
 * included. The canvas's back arrow leads to the caderno.
 *
 * Its own controller rather than more methods on `NotebookPageController`:
 * everything there edits the page, and this writes a row in another module
 * entirely.
 */
class NotebookPageDiagramController extends Controller
{
    public function __construct(
        private readonly DiagramDraftService $drafts,
        private readonly CreateDiagramFromDraft $creator,
        private readonly DiagramModelService $models,
        private readonly CreateDiagramFromModel $modelCreator,
    ) {}

    /**
     * The dialog: new (named) or over an existing one. `?model=` says which of
     * the five items opened it, so the confirm posts to the right endpoint and
     * the name arrives prefilled the way that item would have named it.
     */
    public function target(Request $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $page->setRelation('notebook', $notebook);

        $this->authorize('view', $page);
        $this->authorize('create', Diagram::class);

        $model = DiagramModel::tryFrom((string) $request->query('model'));

        return response()->json([
            'content' => view('notebooks.diagram-target', [
                'notebook' => $notebook,
                'page'     => $page,
                'model'    => $model,
                'action'   => $model
                    ? route('notebooks.pages.diagram.model', [$notebook, $page, $model->value])
                    : route('notebooks.pages.diagram', [$notebook, $page]),
                'name'      => $model ? $model->suffixed($page->title) : $page->title,
                'diagrams'  => $notebook->diagrams()->with('participants:id,name,slug')->get(['id', 'notebook_id', 'name', 'slug', 'status']),
                'newTarget' => DrawNotebookPageRequest::NEW,
            ])->render(),
        ]);
    }

    public function store(DrawNotebookPageRequest $request, Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        $target = $request->targetDiagram();

        $diagram = $this->creator->handle($this->drafts->draft($page), $notebook, $request->diagramName(), $target);

        return $this->drawn($diagram, $target !== null, 'Diagrama', $page);
    }

    /**
     * The same walk, for one of the four MODELS — a sequence, a lifecycle, a
     * data flow, a process. Same dialog, same abilities; only the shape of the
     * reading differs, and that arrives as an enum in the URL.
     */
    public function model(DrawNotebookPageRequest $request, Notebook $notebook, DocumentationPage $page, DiagramModel $model): JsonResponse
    {
        $target = $request->targetDiagram();

        $diagram = $this->modelCreator->handle($this->models->draft($page, $model), $notebook, $request->diagramName(), $target);

        return $this->drawn($diagram, $target !== null, $model->label(), $page);
    }

    /**
     * The Toast that matters belongs to the tab being navigated TO, and it says
     * "confira" because what was just drawn is a reading of somebody's prose,
     * not a fact. It travels as a FLASH, rendered by the layout in the tab that
     * opened; the response's own `message` is shown on the page that stayed
     * put, where "abri em outra aba" is the useful thing to say.
     */
    private function drawn(Diagram $diagram, bool $overwritten, string $what, DocumentationPage $page): JsonResponse
    {
        $verb = $overwritten ? 'redesenhado' : 'criado';

        session()->flash('status', $what . ' ' . $verb . ' a partir de "' . $page->title . '". Confira os blocos antes de salvar.');

        return response()->json([
            'message'        => '"' . $diagram->name . '" ' . $verb . ' — abri em outra aba.',
            'redirect'       => route('diagrams.show', $diagram),
            'modalIdToClose' => 'main-modal',
        ]);
    }
}
