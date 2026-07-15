<?php

namespace App\Services\Flowspec;

use App\Models\DocumentationPage;
use App\Models\FlowspecExample;
use App\Models\Solution;
use Illuminate\Support\Collection;

/**
 * Material de contexto resolvido para uma geração de flowSpec: as Solutions
 * consideradas, suas páginas de documentação (já recortadas ao orçamento),
 * os exemplos do corpus selecionados por tag e as tags que motivaram a
 * seleção — tudo que o FlowspecPromptBuilder precisa, mais o rastro
 * (`omittedPages`, `tags`, exemplos usados) que vira `meta` da mensagem.
 */
final class FlowspecContext
{
    /**
     * @param  Collection<int, Solution>  $solutions
     * @param  Collection<int, DocumentationPage>  $pages
     * @param  list<string>  $omittedPages  títulos cortados por orçamento
     * @param  Collection<int, FlowspecExample>  $examples
     * @param  list<string>  $tags  tags candidatas derivadas do pedido
     */
    public function __construct(
        public readonly Collection $solutions,
        public readonly Collection $pages,
        public readonly array $omittedPages,
        public readonly Collection $examples,
        public readonly array $tags,
    ) {}

    /** Resumo auditável gravado em `flowspec_messages.meta`. */
    public function toMeta(): array
    {
        return [
            'solutions'     => $this->solutions->pluck('name')->all(),
            'pages'         => $this->pages->pluck('title')->all(),
            'omitted_pages' => $this->omittedPages,
            'examples'      => $this->examples->pluck('slug')->all(),
            'tags'          => $this->tags,
        ];
    }
}
