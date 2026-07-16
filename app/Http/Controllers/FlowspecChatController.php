<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFlowspecChatRequest;
use App\Jobs\GenerateFlowspecReply;
use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\Integration;
use App\View\Components\Flowspec\Thread;
use Illuminate\Http\JsonResponse;
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
            'chats' => $request->user()->flowspecChats()->latest('updated_at')->withCount('messages')->get(),
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
            'meta'    => [
                'solution_ids'  => $request->solutionIds(),
                'document_refs' => $request->documentRefs(),
            ],
        ]);

        GenerateFlowspecReply::dispatch($message);

        return response()->json(['redirect' => route('flowspec.show', $chat)]);
    }

    public function show(Request $request, FlowspecChat $chat)
    {
        $this->authorize('view', $chat);

        return view('flowspec.show', [
            'chat' => $chat->load('integration:id,name'),
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

    /**
     * Busca do chips picker "Documentos específicos" — páginas de
     * documentação (de qualquer Solution ou DocumentationGroup) e
     * integrações com documentação própria, combinadas num resultado só.
     * IDs vêm prefixados (`page:{id}`/`integration:{id}`) para o
     * FlowspecDocumentReference/ParsesFlowspecContextInput distinguir os
     * dois tipos ao salvar `meta.document_refs`.
     */
    public function searchDocuments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FlowspecChat::class);

        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json(['results' => []]);
        }

        $pages = DocumentationPage::query()
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->where('title', 'like', "%{$term}%")
            ->with('container')
            ->limit(8)
            ->get()
            ->map(fn (DocumentationPage $page) => [
                'id'   => "page:{$page->id}",
                'name' => "{$page->container->name} — {$page->title}",
                'meta' => 'Página de documentação',
            ]);

        $integrations = Integration::query()
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->where('name', 'like', "%{$term}%")
            ->limit(8)
            ->get(['id', 'name'])
            ->map(fn (Integration $integration) => [
                'id'   => "integration:{$integration->id}",
                'name' => $integration->name,
                'meta' => 'Documentação de integração',
            ]);

        // `collect()` forces a plain Support\Collection before merging: when
        // `$pages` has zero matches, Eloquent\Collection::map() keeps it as an
        // (empty) Eloquent\Collection instead of downgrading to a base
        // Collection — `contains(fn ($item) => ! $item instanceof Model)` is
        // vacuously false over zero items, so the downgrade in Eloquent's
        // `map()` never fires. `$pages->merge($integrations)` then runs
        // Eloquent\Collection::merge(), which assumes every item is a Model
        // and calls `$item->getKey()` — a fatal error on the plain arrays
        // `$integrations` actually holds. Reproduces with any term that
        // matches an integration but no documentation page.
        return response()->json(['results' => collect($pages)->merge($integrations)->take(10)->values()]);
    }
}
