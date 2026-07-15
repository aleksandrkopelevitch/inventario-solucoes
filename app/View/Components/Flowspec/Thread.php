<?php

namespace App\View\Components\Flowspec;

use App\Models\FlowspecChat;
use App\Models\Integration;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Thread de um chat do gerador de flowSpec (F8), renderizável como slot
 * atualizável (`flowspec-thread-slot`): mensagens, bloco de JSON com copiar,
 * badge de validação, formulários de anexar/promover e o marcador de
 * "gerando…" que dispara o polling (flowspec-chat.js).
 */
class Thread extends Component
{
    use Renderable;

    public const DOM_ID = 'flowspec-thread-slot';

    public function __construct(public FlowspecChat $chat) {}

    public static function slot(FlowspecChat $chat): array
    {
        return (new static($chat))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $messages = $this->chat->messages()->get();

        return view('components.flowspec.thread', [
            'domId'        => self::DOM_ID,
            'chat'         => $this->chat,
            'messages'     => $messages,
            'integrations' => Integration::query()->orderBy('name')->get(['id', 'name']),
            // Deriva da coleção já buscada — evita a query extra de
            // isAwaitingReply() (mesma regra: última mensagem é do usuário).
            'awaiting' => $messages->last()?->role === 'user',
        ]);
    }
}
