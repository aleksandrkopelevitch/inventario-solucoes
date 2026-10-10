<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublishNotebookRequest;
use App\Models\Notebook;
use App\View\Components\Notebooks\Index;
use App\View\Components\Notebooks\SharePanel;
use Illuminate\Http\JsonResponse;

/**
 * Deciding whether the internal knowledge base (`/docs`) shows a caderno.
 *
 * Admin only, and it is `NotebookPolicy::administer` that says so rather than
 * `update` — the same rule the magic link answers to, for a reason that is
 * stronger here: a link has to be handed to somebody one at a time, while
 * publishing a caderno to `/docs` puts it in front of everybody at Leo the
 * moment the switch flips.
 *
 * Two screens carry the switch: the cadernos catalog (`/notebooks`, the "Em
 * /docs" column, which is also what answers "what is published today") and the
 * caderno's own share panel, where an admin already is when the question
 * occurs to them. One column, one endpoint, two callers.
 */
class NotebookPublicationController extends Controller
{
    /**
     * Publishes the caderno, or takes it back out.
     *
     * Answers with BOTH slots every time, because the two screens that can flip
     * this switch are different screens. `ajax-slot.js` no-ops on an id that is
     * not on the current page, so sending both is safe and forgetting one
     * leaves the other screen showing a switch in the wrong position
     * (§ Multiple different slots).
     *
     * The filters ride in on the query string for the same reason every other
     * mutation in this app carries them: the catalog slot this rebuilds has to
     * be the list the person was actually looking at.
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
                Index::slot((array) $request->query('filter', [])),
                SharePanel::slot($notebook->fresh()),
            ],
        ]);
    }
}
