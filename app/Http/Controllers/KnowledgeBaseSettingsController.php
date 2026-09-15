<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublishNotebookRequest;
use App\Models\Notebook;
use App\View\Components\Docs\PublicationList;
use App\View\Components\Notebooks\SharePanel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deciding what the internal knowledge base (`/docs`) shows.
 *
 * Admin only, and it is `NotebookPolicy::administer` that says so rather than
 * `update` — the same rule the magic link answers to, for a reason that is
 * stronger here: a link has to be handed to somebody one at a time, while
 * publishing a caderno to `/docs` puts it in front of everybody at Leo the
 * moment the switch flips.
 *
 * The screen and the SWITCH are separate on purpose, and both exist. The screen
 * answers "what is published today", which nothing else in the app could
 * answer; the switch on each caderno's own share panel is how it is normally
 * flipped, because that is where an admin already is when the question occurs
 * to them. One column, one endpoint, two callers.
 */
class KnowledgeBaseSettingsController extends Controller
{
    /** Same action serves the HTML screen and the filtered JSON slot. */
    public function index(Request $request): View|JsonResponse
    {
        $this->authorize('administerAny', Notebook::class);

        $filters = (array) $request->query('filter', []);

        if ($request->wantsJson()) {
            return response()->json([
                'updatableSlots' => [PublicationList::slot($filters)],
            ]);
        }

        return view('docs.settings', ['filters' => $filters]);
    }

    /**
     * Publishes the caderno, or takes it back out.
     *
     * Answers with BOTH slots every time, because the two screens that can flip
     * this switch are different screens: the settings list and the caderno's
     * own share panel. `ajax-slot.js` no-ops on an id that is not on the
     * current page, so sending both is safe and forgetting one leaves the other
     * screen showing a switch in the wrong position (§ Multiple different
     * slots).
     *
     * The filters ride in on the query string for the same reason every other
     * mutation in this app carries them: the slot this rebuilds has to be the
     * list the person was actually looking at.
     */
    public function update(PublishNotebookRequest $request, Notebook $notebook): JsonResponse
    {
        // Written here and not through `update()`: `published_at` is absent
        // from `$fillable` precisely so that an editor cannot reach it by
        // posting a field to the panel they CAN reach (§ the model).
        $notebook->published_at = $request->shouldPublish() ? now() : null;
        $notebook->save();

        return response()->json([
            'type'    => 'success',
            'message' => $request->shouldPublish()
                ? 'Caderno publicado na base de conhecimento.'
                : 'Caderno removido da base de conhecimento.',
            'updatableSlots' => [
                PublicationList::slot((array) $request->query('filter', [])),
                SharePanel::slot($notebook->fresh()),
            ],
        ]);
    }
}
