<?php

namespace App\Http\Controllers;

use App\Enums\PipelineRunStatus;
use App\Http\Requests\StorePipelineRunRequest;
use App\Jobs\RunPipelineLifecycle;
use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use App\Models\PipelineRun;
use App\View\Components\Flowspec\LifecyclePanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Starting and watching a Digibee lifecycle run from the F8 conversation.
 *
 * This is the app's only web surface that reaches the realm, and three things
 * about it are deliberate:
 *
 * - **It authorizes with `run` on the CHAT**, which composes "may see this
 *   conversation" (chats are personal) with "may write" (an editor). A Viewer
 *   who owns the chat reads every flowSpec in it and deploys none.
 * - **One unsettled run per message.** Two runs against the same document would
 *   each write and deploy it, and the job's `WithoutOverlapping` serializes
 *   them rather than preventing them — the second would still happen, just
 *   later, leaving deployments nobody asked for twice over.
 * - **Stale runs are reaped on both paths.** A worker killed mid-job leaves the
 *   row `running` and nothing else would ever move it: the job that would have
 *   finished it no longer exists. So the guard runs when a new one is requested
 *   AND on every poll, which is the same reaping the flowSpec chat needed for
 *   the same reason.
 */
class PipelineRunController extends Controller
{
    public function store(StorePipelineRunRequest $request, FlowspecChat $chat, FlowspecMessage $message): JsonResponse
    {
        $this->authorize('run', $chat);

        if ($message->flowspec_chat_id !== $chat->id || ! is_array($message->flow_spec)) {
            return response()->json([
                'type'    => 'warning',
                'message' => 'Essa mensagem não tem um flowSpec para executar.',
            ], 422);
        }

        $this->reapStale($message);

        if ($this->unsettledFor($message) !== null) {
            return response()->json([
                'type'    => 'warning',
                'message' => 'Já existe uma execução em andamento para esse flowSpec.',
            ], 422);
        }

        $run = PipelineRun::create([
            'flowspec_message_id' => $message->id,
            'user_id'             => $request->user()->id,
            'pipeline_name'       => $request->validated('pipeline_name'),
            'environment'         => $request->validated('environment'),
            'creates'             => (bool) $request->validated('creates', false),
            'status'              => PipelineRunStatus::Pending,
        ]);

        RunPipelineLifecycle::dispatch($run);

        return response()->json([
            'type'           => 'success',
            'message'        => 'Execução enfileirada.',
            'updatableSlots' => [LifecyclePanel::slot($message)],
        ]);
    }

    /**
     * Poll while a run is unsettled.
     *
     * The slot is rendered only when there is something new to show — a round
     * appended since the client last saw one, or the run settling. A poll that
     * re-renders on every tick spends a query and a render cycle on markup the
     * client throws away, and this one ticks for minutes.
     */
    public function status(Request $request, FlowspecChat $chat, FlowspecMessage $message): JsonResponse
    {
        $this->authorize('view', $chat);

        $this->reapStale($message);

        $run = PipelineRun::query()
            ->where('flowspec_message_id', $message->id)
            ->latest('id')
            ->first();

        $rounds = $run?->roundList() ?? [];
        $pending = $run !== null && ! $run->status->settled();
        $seen = (int) $request->query('seen', -1);
        $changed = $seen !== count($rounds);

        return response()->json([
            'pending' => $pending,
            'rounds'  => count($rounds),
            ...($changed || ! $pending
                ? ['updatableSlots' => [LifecyclePanel::slot($message)]]
                : []),
        ]);
    }

    private function unsettledFor(FlowspecMessage $message): ?PipelineRun
    {
        return PipelineRun::query()
            ->where('flowspec_message_id', $message->id)
            ->unsettled()
            ->latest('id')
            ->first();
    }

    /**
     * Marks as failed any run that has gone quiet longer than a worker could
     * plausibly hold it.
     *
     * Without this, one killed worker blocks that message forever: the "one
     * unsettled run per message" rule above would keep refusing, and the only
     * cure would be an UPDATE against the database.
     */
    private function reapStale(FlowspecMessage $message): void
    {
        PipelineRun::query()
            ->where('flowspec_message_id', $message->id)
            ->unsettled()
            ->get()
            ->each(function (PipelineRun $run) {
                if ($run->stale()) {
                    $run->update([
                        'status'      => PipelineRunStatus::Failed,
                        'error'       => 'A execução parou de responder e foi encerrada — o worker da fila pode ter morrido.',
                        'finished_at' => now(),
                    ]);
                }
            });
    }
}
