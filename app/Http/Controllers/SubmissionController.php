<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionRequest;
use App\Http\Requests\UpdateSubmissionFieldRequest;
use App\Http\Requests\UpdateSubmissionRequest;
use App\Models\Person;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\DetailHeader;
use App\View\Components\Submissions\Index as SubmissionsIndex;
use App\View\Components\Submissions\ResultsCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Submissions to the IT Architecture Committee (CATI).
 *
 * Same HTML/JSON action pattern as the rest of the app: a normal GET renders
 * the page, an AJAX GET returns the updatable slots.
 */
class SubmissionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Submission::class);

        $filters = (array) $request->input('filter', []);

        if ($request->wantsJson()) {
            return response()->json(['updatableSlots' => [
                SubmissionsIndex::slot($filters),
                ResultsCount::slot($filters),
            ]]);
        }

        return view('submissions.index', [
            'filters'   => $filters,
            'solutions' => Solution::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function show(Submission $submission)
    {
        $this->authorize('view', $submission);

        $submission->loadMissing(['solution', 'requester', 'sections', 'sources']);

        // Every section row must exist for the page to have all eleven cards —
        // and for a key added to the enum after this record was created.
        $submission->ensureSections();

        return view('submissions.show', [
            'submission' => $submission->fresh(['solution', 'requester', 'sections', 'sources']),
            'chat'       => $this->chatFor($submission),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->authorize('create', Submission::class);

        return $this->panel(new Submission, (array) $request->query('filter', []));
    }

    public function edit(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('update', $submission);

        return $this->panel($submission, (array) $request->query('filter', []));
    }

    public function store(StoreSubmissionRequest $request): JsonResponse
    {
        $submission = Submission::create([
            ...$request->validated(),
            'slug'          => $this->uniqueSlug($request->validated('name'), null),
            'created_by_id' => $request->user()->id,
        ]);

        $submission->ensureSections();

        return response()->json([
            'type'    => 'success',
            'message' => 'Submissão criada.',
            // Straight to the record — there is nothing to do on the list.
            'redirect' => route('submissions.show', $submission),
        ]);
    }

    public function update(UpdateSubmissionRequest $request, Submission $submission): JsonResponse
    {
        $submission->update($request->validated());

        return $this->saved('Alterações salvas.', $submission, (array) $request->query('filter', []));
    }

    /** One field, edited in place on the detail header. */
    public function updateField(UpdateSubmissionFieldRequest $request, Submission $submission): JsonResponse
    {
        $submission->update($request->validated());

        return response()->json([
            'type'    => 'success',
            'message' => 'Alteração salva.',
            // The checklist reads the solution and the requester, so changing
            // either from the header changes which facts are known.
            'updatableSlots' => [
                DetailHeader::slot($submission->fresh(['solution', 'requester'])),
                Checklist::slot($submission->fresh(['sections', 'sources', 'solution'])),
            ],
        ]);
    }

    public function destroy(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('delete', $submission);

        $submission->delete();

        return response()->json([
            'type'     => 'success',
            'message'  => 'Submissão removida.',
            'redirect' => route('submissions.index', ['filter' => (array) $request->query('filter', [])]),
        ]);
    }

    /** @param  array<string, mixed>  $filters */
    private function panel(Submission $submission, array $filters): JsonResponse
    {
        return response()->json([
            'content' => view('submissions.panels.form', [
                'submission' => $submission,
                'filters'    => $filters,
                'solutions'  => Solution::query()->orderBy('name')->get(['id', 'name']),
                'people'     => Person::query()->orderBy('name')->get(['id', 'name']),
            ])->render(),
        ]);
    }

    /** @param  array<string, mixed>  $filters */
    private function saved(string $message, Submission $submission, array $filters): JsonResponse
    {
        return response()->json([
            'type'    => 'success',
            'message' => $message,
            // Both are sent unconditionally: ajax-slot.js no-ops on an id that
            // isn't on the current page, so the same response serves an edit
            // made from the list and one made from the detail page.
            'updatableSlots' => [
                SubmissionsIndex::slot($filters),
                ResultsCount::slot($filters),
                DetailHeader::slot($submission->fresh(['solution', 'requester'])),
            ],
            'js' => "document.querySelector('[data-ak-panel-close]')?.click()",
        ]);
    }

    private function uniqueSlug(string $name, ?Submission $submission): string
    {
        $base = Str::slug($name) ?: 'submissao';
        $slug = $base;
        $suffix = 1;

        while (Submission::where('slug', $slug)
            ->when($submission, fn ($q) => $q->whereKeyNot($submission->getKey()))
            ->exists()) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }

    private function chatFor(Submission $submission): SubmissionChat
    {
        return $submission->chats()->firstOrCreate(['user_id' => request()->user()->id]);
    }
}
