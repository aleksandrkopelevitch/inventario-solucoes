<?php

namespace App\Http\Controllers;

use App\Actions\WriteNotebookDiagram;
use App\Http\Requests\StoreNotebookDiagramRequest;
use App\Models\Diagram;
use App\Models\Notebook;
use Illuminate\Http\JsonResponse;

/**
 * A caderno's drawings — the one place they are listed as the caderno's, and
 * the one place a drawing can still be started by hand.
 *
 * A diagram belongs to its caderno, not to a page of it (see `Diagram`), so
 * this is reached from the caderno's rail and looks the same from every page.
 * `/diagrams` and a solution's page LIST drawings across cadernos; neither
 * creates one any more, because a drawing born there would belong nowhere.
 */
class NotebookDiagramController extends Controller
{
    /** The lookup modal (`#main-modal`): one row per drawing, with the systems it names. */
    public function index(Notebook $notebook): JsonResponse
    {
        $this->authorize('view', $notebook);
        $this->authorize('viewAny', Diagram::class);

        return response()->json([
            'content' => view('notebooks.diagrams', [
                'notebook' => $notebook,
                'diagrams' => $notebook->diagrams()
                    ->with(['participants:id,name,slug', 'media'])
                    ->get(['id', 'notebook_id', 'name', 'slug', 'status', 'chain']),
            ])->render(),
        ]);
    }

    /**
     * A blank diagram of this caderno — one free-text root block named after
     * it — and straight to its canvas, where everything else is authored.
     */
    public function store(StoreNotebookDiagramRequest $request, Notebook $notebook, WriteNotebookDiagram $writer): JsonResponse
    {
        $diagram = $writer->handle($notebook, chain: null, name: $request->validated('name'));

        return response()->json([
            'type'     => 'success',
            'message'  => 'Diagrama criado.',
            'redirect' => route('diagrams.show', $diagram),
        ]);
    }
}
