<?php

namespace App\Services\Flowspec;

/**
 * Saída de FlowspecGenerationService::generate(): o flowSpec validado (ou a
 * melhor tentativa, quando o loop esgota), o texto bruto da última resposta
 * do modelo (usado como fala do assistente quando não veio JSON) e o rastro
 * auditável — contexto usado, tentativas com erros, fixes e tokens — que é
 * gravado em `flowspec_messages.meta`.
 */
final class FlowspecGenerationResult
{
    /**
     * @param  array<string, mixed>|null  $document
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly ?array $document,
        public readonly string $text,
        public readonly bool $validated,
        public readonly array $meta,
    ) {}
}
