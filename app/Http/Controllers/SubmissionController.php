<?php

namespace App\Http\Controllers;

use App\Actions\Cati\SeedSubmissionChatOpening;
use App\Enums\SubmissionStatus;
use App\Http\Requests\StoreSubmissionRequest;
use App\Http\Requests\UpdateSubmissionFieldRequest;
use App\Jobs\PreReviewSubmission;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\DetailHeader;
use App\View\Components\Submissions\Index as SubmissionsIndex;
use App\View\Components\Submissions\PreReview;
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

    /**
     * Just a name and, optionally, the catalog Solution being proposed for.
     * Everything else (requester, committee date, ticket) is filled in on the
     * detail header once the record exists — asking for it here would be
     * filling it in twice, once blind.
     */
    public function create(Request $request): JsonResponse
    {
        $this->authorize('create', Submission::class);

        return response()->json([
            'content' => view('submissions.panels.form', [
                'filters'   => (array) $request->query('filter', []),
                'solutions' => Solution::query()->orderBy('name')->get(['id', 'name']),
            ])->render(),
        ]);
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

    /** One field, edited in place on the detail header. */
    public function updateField(UpdateSubmissionFieldRequest $request, Submission $submission): JsonResponse
    {
        $wasSubmitted = $submission->status === SubmissionStatus::Submitted;

        $submission->update($request->validated());

        $this->preReviewOnSubmit($submission, $wasSubmitted);

        return response()->json([
            'type'    => 'success',
            'message' => 'Alteração salva.',
            // The checklist reads the solution and the requester, so changing
            // either from the header changes which facts are known.
            'updatableSlots' => [
                DetailHeader::slot($submission->fresh(['solution', 'requester'])),
                Checklist::slot($submission->fresh(['sections', 'sources', 'solution'])),
                // Submitting from the header starts a pre-review; without this
                // the card would keep showing the old state until a reload.
                PreReview::slot($submission->fresh()),
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

    /**
     * Reads the submission as the committee would, the moment it is submitted.
     *
     * The findings are worth most in the gap between submitting and the
     * meeting, which is exactly when nobody thinks to press a button. Fired on
     * the TRANSITION into `submitted` only — re-saving an already-submitted
     * record must not queue another model call, and the button is still there
     * for a deliberate re-run.
     */
    private function preReviewOnSubmit(Submission $submission, bool $wasSubmitted): void
    {
        if ($wasSubmitted || $submission->status !== SubmissionStatus::Submitted) {
            return;
        }

        if ($submission->isPreReviewRunning()) {
            return;
        }

        $submission->update(['pre_review_requested_at' => now()]);

        PreReviewSubmission::dispatch($submission);
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
        $chat = $submission->chats()->firstOrCreate(['user_id' => request()->user()->id]);

        app(SeedSubmissionChatOpening::class)->handle($chat);

        return $chat;
    }
}
