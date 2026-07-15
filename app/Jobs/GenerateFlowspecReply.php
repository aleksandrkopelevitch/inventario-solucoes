<?php

namespace App\Jobs;

use App\Models\FlowspecMessage;
use App\Services\Flowspec\FlowspecGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Gera a resposta do assistente para uma mensagem do chat de flowSpec (F8).
 * A UI não espera o job: o thread mostra "gerando…" enquanto a última
 * mensagem do chat for do usuário, e um polling leve (flowspec-chat.js)
 * atualiza o slot quando esta resposta é persistida — inclusive a de falha,
 * criada em failed(), para o chat nunca ficar pendente para sempre.
 */
class GenerateFlowspecReply implements ShouldQueue
{
    use Queueable;

    /** Até 3 tentativas de geração/correção contra a API — bem abaixo do retry_after (900s). */
    public int $timeout = 600;

    /**
     * >1: cada "espera" do WithoutOverlapping (mensagem seguinte no mesmo
     * chat, enfileirada antes desta terminar) consome uma tentativa via
     * release() — não é uma falha real, só fila. Acima do que um usuário
     * plausivelmente dispara em sequência antes desta terminar.
     */
    public int $tries = 5;

    public function __construct(public FlowspecMessage $userMessage) {}

    /**
     * Serializa a geração por chat: duas mensagens do usuário em sequência
     * rápida (duplo clique antes do botão desabilitar, duas abas) não podem
     * rodar em paralelo — cada uma leria o mesmo histórico e criaria uma
     * resposta de assistant concorrente, quebrando o modelo de "um turno
     * pendente por vez" que isAwaitingReply() pressupõe. A job bloqueada
     * volta pra fila (não é descartada) e roda assim que a anterior liberar,
     * já vendo a resposta anterior no histórico.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->userMessage->flowspec_chat_id))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(5),
        ];
    }

    public function handle(FlowspecGenerationService $service): void
    {
        $result = $service->generate($this->userMessage);

        $content = match (true) {
            $result->document === null => $result->text,
            $result->validated         => 'flowSpec gerado e validado — pronto para colar no canvas da Digibee.',
            default                    => 'flowSpec gerado, mas a validação ainda apontou pendências depois de todas as tentativas — revise os erros abaixo antes de colar.',
        };

        $this->userMessage->loadMissing('chat');

        $this->userMessage->chat->messages()->create([
            'role'      => 'assistant',
            'content'   => $content,
            'flow_spec' => $result->document,
            'meta'      => [...$result->meta, 'validated' => $result->validated],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->userMessage->loadMissing('chat');

        $this->userMessage->chat->messages()->create([
            'role'    => 'assistant',
            'content' => 'Não consegui gerar o flowSpec — a chamada ao modelo falhou. Tente novamente em instantes.',
            'meta'    => ['status' => 'failed', 'error' => $exception?->getMessage()],
        ]);
    }
}
