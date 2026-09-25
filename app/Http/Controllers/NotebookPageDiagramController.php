<?php

namespace App\Http\Controllers;

use App\Actions\Documentation\CreateDiagramFromDraft;
use App\Actions\Documentation\CreateDiagramFromModel;
use App\Enums\DiagramModel;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\Documentation\DiagramDraftService;
use App\Services\Documentation\DiagramModelService;
use Illuminate\Http\JsonResponse;

/**
 * "Desenhar esta página" — one drawing, proposed from a page's prose.
 *
 * What comes out is an ordinary `Diagram` at its own address, not something
 * attached to the page: a page CITES drawings (`{% diagram %}`), and giving one
 * a drawing of its own is the relation that was deliberately removed when
 * `Integration` became `Diagram`. So this creates and redirects, and whoever
 * wants the picture in the text adds the citation themselves.
 *
 * The redirect lands in a NEW TAB (`data-ak-ajax-target="_blank"` on the five
 * menu items). Nothing links the page and the drawing, so a diagram's own back
 * arrow goes to the diagrams index — which is where the author of a page ended
 * up, one click from prose they had not finished. Opening beside it costs
 * nothing and the page stays exactly as it was, unsaved edits included.
 *
 * Its own controller rather than a tenth method on `NotebookPageController`:
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

    public function store(Notebook $notebook, DocumentationPage $page): JsonResponse
    {
        // Already in hand from the route binding — without it the policy is
        // what lazy-loads it (see .claude/rules/eloquent-strict-and-search.md).
        $page->setRelation('notebook', $notebook);

        // Two abilities, because two things happen: a page is read and a
        // drawing is written. `create` on Diagram is the one that matters — a
        // Viewer may well be able to read this caderno, and must not be able to
        // put a diagram in the catalog from it.
        $this->authorize('view', $page);
        $this->authorize('create', Diagram::class);

        $diagram = $this->creator->handle($this->drafts->draft($page));

        // The Toast that matters belongs to the page being navigated TO, and it
        // says "confira" because what was just created is a reading of
        // somebody's prose, not a fact. It travels as a FLASH: the response's
        // own `message` is shown on the page that stayed put, where "abri em
        // outra aba" is the useful thing to say, and the flash is rendered by
        // the layout in the tab that actually opened. (It survived the same way
        // when this replaced the document — there the response's Toast was
        // shown and destroyed in the same instant.)
        session()->flash('status', 'Diagrama criado a partir de "' . $page->title . '". Confira os blocos antes de salvar.');

        return response()->json([
            'message'  => 'Diagrama criado — abri em outra aba.',
            'redirect' => route('diagrams.show', $diagram),
        ]);
    }

    /**
     * The same walk, for one of the four MODELS — a sequence, a lifecycle, a
     * data flow, a process.
     *
     * It shares this controller with the free-graph draft because it is the
     * same gesture answering the same question ("draw what this page says"),
     * and the same two abilities apply: the page is read, a diagram is written.
     * What differs is only which shape the reading takes, and that arrives as
     * an enum in the URL.
     */
    public function model(Notebook $notebook, DocumentationPage $page, DiagramModel $model): JsonResponse
    {
        $page->setRelation('notebook', $notebook);

        $this->authorize('view', $page);
        $this->authorize('create', Diagram::class);

        $diagram = $this->modelCreator->handle($this->models->draft($page, $model));

        session()->flash('status', $model->label() . ' gerada a partir de "' . $page->title . '". Confira os blocos antes de salvar.');

        return response()->json([
            'message'  => $model->label() . ' gerada — abri em outra aba.',
            'redirect' => route('diagrams.show', $diagram),
        ]);
    }
}
