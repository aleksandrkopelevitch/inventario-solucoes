<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFlowspecChatRequest;
use App\Jobs\GenerateFlowspecReply;
use App\Models\FlowspecChat;
use App\Models\Solution;
use App\View\Components\Flowspec\Thread;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chat do gerador de flowSpec Digibee (F8). A conversa é assíncrona: o POST
 * persiste a mensagem do usuário e despacha GenerateFlowspecReply; o thread
 * (Flowspec\Thread, slot atualizável) mostra "gerando…" e o polling de
 * `status` troca o slot quando a resposta chega.
 */
class FlowspecChatController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', FlowspecChat::class);

        return view('flowspec.index', [
            'chats'     => $request->user()->flowspecChats()->latest('updated_at')->withCount('messages')->get(),
            'solutions' => Solution::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreFlowspecChatRequest $request)
    {
        $chat = $request->user()->flowspecChats()->create([
            'title' => Str::limit($request->validated('message'), 80),
        ]);

        $message = $chat->messages()->create([
            'role'    => 'user',
            'content' => $request->validated('message'),
            'meta'    => ['solution_ids' => $request->validated('solutions') ?? []],
        ]);

        GenerateFlowspecReply::dispatch($message);

        return response()->json(['redirect' => route('flowspec.show', $chat)]);
    }

    public function show(Request $request, FlowspecChat $chat)
    {
        $this->authorize('view', $chat);

        return view('flowspec.show', [
            'chat'      => $chat->load('integration:id,name'),
            'solutions' => Solution::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Polling do thread enquanto o job de geração roda (flowspec-chat.js).
     * Só monta o slot (mensagens + integrações + render do Blade) quando a
     * resposta já chegou — enquanto `pending`, o cliente descarta o slot, e a
     * geração pode levar minutos, então nada disso deve rodar a cada tick.
     */
    public function status(Request $request, FlowspecChat $chat)
    {
        $this->authorize('view', $chat);

        $pending = $chat->isAwaitingReply();

        return response()->json([
            'pending'        => $pending,
            'updatableSlots' => $pending ? [] : [Thread::slot($chat)],
        ]);
    }
}
