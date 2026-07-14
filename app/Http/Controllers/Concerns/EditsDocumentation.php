<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\Documentable;
use App\Http\Requests\SaveDocumentationRequest;
use App\Http\Requests\UploadDocumentationMediaRequest;
use App\Support\GitbookRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Lógica compartilhada do editor de documentação por blocos (Editor.js) entre
 * os recursos documentáveis (Solution, Integration). Cada controller resolve o
 * seu model + contexto de rota (URLs de save/upload/voltar, migalha) e delega o
 * salvamento do Markdown, o upload de mídia e a montagem da página aqui.
 */
trait EditsDocumentation
{
    /**
     * Monta a página do editor (mesma view para todos os recursos). Admins
     * veem o Editor.js; demais usuários veem o render read-only (GitbookRenderer),
     * decidido no cliente por `canEdit`.
     *
     * @param  array{save: string, upload: string, back: string}  $urls
     */
    protected function documentationView(Documentable $model, array $urls, string $eyebrow, string $backLabel): View
    {
        $canEdit = request()->user()->can('update', $model);

        return view('documentation.edit', [
            'title'         => $model->documentationTitle(),
            'eyebrow'       => $eyebrow,
            'backUrl'       => $urls['back'],
            'backLabel'     => $backLabel,
            'saveUrl'       => $urls['save'],
            'uploadUrl'     => $urls['upload'],
            'documentation' => $model->documentation,
            'canEdit'       => $canEdit,
            // Só quem não pode editar recebe o HTML já renderizado (o editor
            // monta o seu a partir do Markdown cru no cliente).
            'renderedHtml' => $canEdit ? '' : app(GitbookRenderer::class)->render($model->documentation),
        ]);
    }

    /** Salva o Markdown (+ notação GitBook) serializado pelo editor. */
    protected function persistDocumentation(SaveDocumentationRequest $request, Documentable $model): JsonResponse
    {
        $model->update(['documentation' => $request->validated()['documentation'] ?? null]);

        return response()->json([
            'type'    => 'success',
            'message' => 'Documentação salva.',
        ]);
    }

    /**
     * Recebe uma imagem/arquivo do editor e o guarda na coleção `docs` do
     * model. Resposta no formato esperado pelos plugins Image/Attaches do
     * Editor.js: `{success: 1, file: {url, ...}}`. A url é a rota autenticada
     * `files.show` (/files/{id}); o serializer usa `mediaId` para reescrever
     * como /files/{id} no Markdown.
     *
     * Dois caminhos, exclusivos entre si (ver UploadDocumentationMediaRequest):
     * - `file`: multipart (upload/arrastar/colar blob) — Image e Attaches.
     * - `url`:  imagem colada de site externo (só o Image plugin, via byUrl).
     *   Baixamos e rehospedamos, restrito a MIMEs de imagem, para nunca deixar
     *   um <img> apontando pra domínio de terceiros.
     */
    protected function storeDocumentationMedia(UploadDocumentationMediaRequest $request, Documentable $model): JsonResponse
    {
        $media = $request->filled('url')
            ? $model->addMediaFromUrl(
                $request->input('url'),
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            )->toMediaCollection(Documentable::DOCS_COLLECTION)
            : $model->addMediaFromRequest('file')->toMediaCollection(Documentable::DOCS_COLLECTION);

        return response()->json([
            'success' => 1,
            'file'    => [
                'url'       => route('files.show', $media),
                'mediaId'   => $media->id,
                'name'      => $media->file_name,
                'title'     => $media->file_name,
                'size'      => $media->size,
                'extension' => $media->extension,
            ],
        ]);
    }
}
