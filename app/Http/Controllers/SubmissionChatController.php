<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionSectionState;
use App\Http\Requests\StoreSubmissionChatMessageRequest;
use App\Jobs\GenerateSubmissionChatReply;
use App\Models\Submission;
use App\Models\SubmissionChat;
use App\Models\SubmissionMessage;
use App\View\Components\Submissions\ChatThread;
use App\View\Components\Submissions\Checklist;
use App\View\Components\Submissions\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionChatController extends Controller
{
    public function store(StoreSubmissionChatMessageRequest $request, Submission $submission): JsonResponse
    {
        $chat = $submission->chats()->firstOrCreate(['user_id' => $request->user()->id]);

        if ($chat->isAwaitingReply()) {
            return response()->json([
                'type'    => 'warning',
                'message' => 'Ainda estou respondendo a mensagem anterior.',
            ], 422);
        }

        $message = $chat->messages()->create([
            'role'    => 'user',
            'content' => $request->validated('message'),
        ]);

        GenerateSubmissionChatReply::dispatch($message);

        return response()->json([
            'type'           => 'success',
            'updatableSlots' => [ChatThread::slot($chat)],
            // The composer isn't inside the swapped slot, so it isn't reset by
            // updateSlots() on its own.
            'js' => "document.dispatchEvent(new CustomEvent('ak:cati-chat-composer-reset'))",
        ]);
    }

    /**
     * Polled while the reply job runs.
     *
     * Stays cheap while pending on purpose: the slot is only built once the
     * reply exists. Rendering it on every tick (2.5s, for a job that can take
     * minutes) would burn a full query+render cycle on data the client throws
     * away.
     */
    public function status(Submission $submission, SubmissionChat $chat): JsonResponse
    {
        $this->authorize('view', $submission);
        abort_unless($chat->submission_id === $submission->id, 404);

        $pending = $chat->isAwaitingReply();

        return response()->json([
            'pending'        => $pending,
            'updatableSlots' => $pending ? [] : [ChatThread::slot($chat)],
        ]);
    }

    /**
     * Applies a reply's drafts into their sections.
     *
     * Deliberately different from the documentation assistant, where "Aplicar"
     * is bookkeeping and the client pushes Markdown into an editor: there is no
     * editor here, so this WRITES. What it writes stays `drafted` — applying is
     * not signing, and confirming is a separate gesture.
     */
    public function apply(Request $request, Submission $submission, SubmissionMessage $message): JsonResponse
    {
        $this->authorize('update', $submission);

        $message->loadMissing('chat');
        abort_unless($message->chat->submission_id === $submission->id, 404);

        $applied = 0;

        foreach ($message->drafts ?? [] as $draft) {
            $key = SubmissionSectionKey::tryFrom($draft['key'] ?? '');

            if ($key === null) {
                continue;
            }

            $submission->section($key)->update([
                'content' => $draft['markdown'],
                'state'   => SubmissionSectionState::Drafted,
                // Provenance: a generated document is trustworthy exactly as
                // far as a reviewer can trace it.
                'provenance'    => ['source' => 'chat', 'message_id' => $message->id],
                'updated_by_id' => $request->user()->id,
            ]);

            $applied++;
        }

        if ($applied === 0) {
            return response()->json([
                'type'    => 'warning',
                'message' => 'Esta resposta não trouxe rascunho para aplicar.',
            ], 422);
        }

        if ($message->applied_at === null) {
            $message->update(['applied_at' => now()]);
        }

        $submission->load(['sections', 'sources', 'solution']);

        return response()->json([
            'type'           => 'success',
            'message'        => $applied === 1 ? 'Rascunho aplicado à seção.' : "Rascunho aplicado a {$applied} seções.",
            'updatableSlots' => [
                Sections::slot($submission),
                Checklist::slot($submission),
                ChatThread::slot($message->chat),
            ],
        ]);
    }
}
