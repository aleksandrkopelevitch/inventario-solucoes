<?php

namespace App\Services\Documentation;

use App\Models\DocumentationAiGeneration;
use App\Models\Solution;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Responses\AgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Laravel\Ai\agent;

/**
 * Gera um rascunho de documentação a partir de um pedido + documentos de
 * contexto da Solução. Documentos de texto vão embutidos no prompt (respeitando
 * um orçamento de caracteres); PDFs e imagens vão como anexos nativos ao modelo
 * (laravel/ai). A saída é Markdown+notação GitBook no subconjunto que o editor
 * entende — carregado no editor para revisão, nunca gravado direto.
 */
class DocumentationDraftService
{
    /** Extensões tratadas como texto embutido no prompt. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'json', 'yaml', 'yml'];

    public function __construct(
        private readonly DocumentationDraftPromptBuilder $prompts,
    ) {}

    public function generate(DocumentationAiGeneration $generation): DocumentationDraftResult
    {
        // Job despacha um model recém-deserializado (SerializesModels) sem
        // relações carregadas — strict mode não arma o guard em single fetch,
        // então o eager load é explícito (ver CLAUDE.md).
        $generation->loadMissing(['solution', 'target']);

        /** @var Solution $solution */
        $solution = $generation->solution;
        $target = $generation->target;

        // Cap defensivo (o request não limita media_ids): mantém os N primeiros
        // na ordem da coleção e sinaliza os excedentes em `omitted_context` — o
        // usuário selecionou-os explicitamente, então não somem sem registro
        // (mesmo tratamento de `omitted_attachments`).
        $max = (int) config('services.documentation_ai.max_context_documents');
        $selectedAll = $this->selectedMedia($solution, $generation->context_media_ids ?? []);
        $selected = $selectedAll->take($max)->values();
        $omittedContext = $selectedAll->slice($max)->pluck('file_name')->values()->all();

        [$textDocs, $attachments, $attachedMeta, $omittedAttachments] = $this->partition($selected);

        $userPrompt = $this->prompts->userPrompt(
            $target,
            $solution,
            // O conteúdo atual NÃO é truncado: a saída da IA substitui a página
            // inteira no editor, então qualquer trecho não enviado ao modelo
            // sumiria da reescrita. Ele cabe folgado na janela do modelo (o
            // request já limita a 500k chars ~= 125k tokens); os documentos de
            // contexto, sim, têm orçamento próprio por serem vários e somarem.
            $generation->existing_content,
            $generation->prompt,
            $textDocs,
        );

        $response = $this->prompt($userPrompt, $attachments);

        return new DocumentationDraftResult(
            markdown: $this->cleanFence($response->text),
            meta: [
                'provider' => config('services.documentation_ai.provider'),
                'model'    => config('services.documentation_ai.model'),
                'tokens'   => [
                    'prompt'     => $response->usage->promptTokens,
                    'completion' => $response->usage->completionTokens,
                    // Zerados hoje (o AnthropicProvider do laravel/ai 0.3.2 não
                    // marca cache_control) — gravados para dar visibilidade quando
                    // o prompt caching entrar (ver plano de otimização, Fase 2).
                    'cache_write' => $response->usage->cacheWriteInputTokens,
                    'cache_read'  => $response->usage->cacheReadInputTokens,
                ],
                'inlined'             => $textDocs->pluck('name')->all(),
                'attached'            => $attachedMeta,
                'omitted_attachments' => $omittedAttachments,
                'omitted_context'     => $omittedContext,
            ],
        );
    }

    /**
     * Documentos de contexto escolhidos, na ordem da coleção (o cap por
     * `max_context_documents` é aplicado por quem chama, para poder sinalizar
     * os excedentes).
     *
     * @param  list<int>  $ids
     * @return Collection<int, Media>
     */
    private function selectedMedia(Solution $solution, array $ids): Collection
    {
        $ids = array_map(intval(...), $ids);

        return $solution->getMedia(Solution::CONTEXT_COLLECTION)
            ->when($ids !== [], fn (Collection $m) => $m->whereIn('id', $ids))
            ->values();
    }

    /**
     * Separa em documentos de texto (embutidos, respeitando o orçamento de
     * caracteres) e anexos nativos (PDF/imagem).
     *
     * @param  Collection<int, Media>  $media
     * @return array{0: Collection<int, array{name: string, content: string}>, 1: list<object>, 2: list<array{id: int, name: string, kind: string}>, 3: list<string>}
     */
    private function partition(Collection $media): array
    {
        $budget = (int) config('services.documentation_ai.doc_budget_chars');
        $maxAttachmentBytes = (int) config('services.documentation_ai.max_attachment_bytes');
        $textDocs = collect();
        $attachments = [];
        $attachedMeta = [];
        $omittedAttachments = [];
        $attachmentBytes = 0;

        foreach ($media as $item) {
            $ext = strtolower((string) $item->extension);
            $mime = (string) $item->mime_type;

            if (in_array($ext, self::TEXT_EXTENSIONS, true) || str_starts_with($mime, 'text/')) {
                if ($budget <= 0) {
                    continue; // orçamento esgotado — omite os demais textos
                }
                // Lê só o necessário do arquivo (UTF-8: até 4 bytes/char, então
                // (budget+1)*4 garante pelo menos budget+1 chars) em vez de
                // carregar um arquivo de até 20MB inteiro na memória para truncar.
                $content = (string) file_get_contents($item->getPath(), false, null, 0, ($budget + 1) * 4);
                if (mb_strlen($content) > $budget) {
                    $content = mb_substr($content, 0, $budget) . "\n\n[documento truncado]";
                }
                $budget -= mb_strlen($content);
                $textDocs->push(['name' => $item->file_name, 'content' => $content]);

                continue;
            }

            $isImage = str_starts_with($mime, 'image/');
            $isPdf = $mime === 'application/pdf' || $ext === 'pdf';

            if (! $isImage && ! $isPdf) {
                continue;
            }

            // Teto agregado de bytes: estourou, omite este e sinaliza — cada
            // arquivo já é <= 20MB pela validação do request, então o primeiro
            // anexo sempre cabe abaixo do limite de ~32MB/requisição da API.
            if ($maxAttachmentBytes > 0 && $attachmentBytes + (int) $item->size > $maxAttachmentBytes) {
                $omittedAttachments[] = $item->file_name;

                continue;
            }

            $attachmentBytes += (int) $item->size;

            if ($isImage) {
                $attachments[] = new LocalImage($item->getPath(), $mime);
                $attachedMeta[] = ['id' => $item->id, 'name' => $item->file_name, 'kind' => 'image'];
            } else {
                $attachments[] = new LocalDocument($item->getPath(), $mime);
                $attachedMeta[] = ['id' => $item->id, 'name' => $item->file_name, 'kind' => 'pdf'];
            }
        }

        return [$textDocs, $attachments, $attachedMeta, $omittedAttachments];
    }

    /**
     * Remove uma cerca de código que envolva a resposta inteira (o modelo às
     * vezes embrulha tudo em ```markdown … ```), preservando cercas internas.
     */
    private function cleanFence(string $text): string
    {
        $trimmed = trim($text);

        if (preg_match('/^```(?:markdown|md)?\s*\n(.*)\n```$/s', $trimmed, $m) === 1) {
            return trim($m[1]) . "\n";
        }

        return $trimmed . "\n";
    }

    /**
     * Protected para os testes substituírem a chamada real à API por um dublê.
     *
     * @param  list<object>  $attachments
     */
    protected function prompt(string $prompt, array $attachments = []): AgentResponse
    {
        return agent(instructions: $this->prompts->systemPrompt())->prompt(
            $prompt,
            attachments: $attachments,
            provider: config('services.documentation_ai.provider'),
            model: config('services.documentation_ai.model'),
            timeout: (int) config('services.documentation_ai.timeout'),
        );
    }
}
