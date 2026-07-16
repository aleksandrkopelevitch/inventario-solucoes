<?php

namespace App\Services\Documentation;

/**
 * Resultado de uma geração do "Assiste IA": o Markdown gerado (já limpo de
 * cercas de código acidentais) e metadados auditáveis (provider/model/tokens,
 * documentos anexados/embutidos).
 */
final class DocumentationDraftResult
{
    /** @param  array<string, mixed>  $meta */
    public function __construct(
        public readonly string $markdown,
        public readonly array $meta,
    ) {}
}
