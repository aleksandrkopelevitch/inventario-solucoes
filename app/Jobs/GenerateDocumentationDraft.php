<?php

namespace App\Jobs;

use App\Models\DocumentationAiGeneration;
use App\Services\Documentation\DocumentationDraftService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Gera o rascunho do "Assiste IA" para uma página/integração. A UI não espera:
 * docs-ai.js faz polling na rota de status até o registro sair de `pending`. O
 * resultado (Markdown) é carregado no editor para revisão. failed() marca o
 * registro como `failed` para o polling nunca ficar pendurado.
 */
class GenerateDocumentationDraft implements ShouldQueue
{
    use Queueable;

    /** Bem abaixo do retry_after (900s) da fila. */
    public int $timeout = 600;

    /**
     * >1: cada "espera" do WithoutOverlapping (novo pedido para o mesmo alvo
     * antes deste terminar) volta pra fila via release() e consome uma
     * tentativa — não é falha real, só fila.
     */
    public int $tries = 5;

    public function __construct(public DocumentationAiGeneration $generation) {}

    /**
     * Serializa a geração por alvo (página/integração): dois pedidos em
     * sequência rápida para a mesma página não podem rodar em paralelo. A job
     * bloqueada volta pra fila e roda quando a anterior liberar.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->generation->target_type . ':' . $this->generation->target_id))
                ->expireAfter($this->timeout + 30)
                ->releaseAfter(5),
        ];
    }

    public function handle(DocumentationDraftService $service): void
    {
        $result = $service->generate($this->generation);

        $this->generation->update([
            'status' => 'completed',
            'result' => $result->markdown,
            'meta'   => $result->meta,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->generation->update([
            'status' => 'failed',
            'error'  => $exception?->getMessage(),
        ]);
    }
}
