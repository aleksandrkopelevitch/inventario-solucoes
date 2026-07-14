<?php

namespace App\Contracts;

use Spatie\MediaLibrary\HasMedia;

/**
 * Um model que carrega documentação rica (coluna `documentation`, Markdown +
 * notação estendida estilo GitBook) e mídia embutida nela (coleção `docs` do
 * Spatie MediaLibrary, referenciada por `/files/{id}` dentro do texto).
 *
 * Implementado por Solution e Integration; consumido pelo editor de blocos
 * (Editor.js) via App\Http\Controllers\Concerns\EditsDocumentation e pelo
 * render read-only App\Support\GitbookRenderer.
 *
 * @property string|null $documentation
 */
interface Documentable extends HasMedia
{
    /** Coleção de mídia onde as imagens/arquivos da documentação são guardados. */
    public const DOCS_COLLECTION = 'docs';

    /** Rótulo humano do recurso, para o título da página do editor. */
    public function documentationTitle(): string;
}
