<?php

namespace App\Services\Flowspec;

use App\Models\DocumentationPage;
use App\Models\FlowspecExample;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Support\Collection;

/**
 * Material de contexto resolvido para uma geração de flowSpec: as Solutions
 * consideradas, suas páginas de documentação e a documentação das
 * integrações em que elas participam (já recortadas ao orçamento — ou,
 * quando o pedido veio com `document_refs` explícitos do chips picker,
 * exatamente os documentos escolhidos, sem scoring nem corte), os exemplos
 * do corpus selecionados por tag e as tags que motivaram a seleção — tudo
 * que o FlowspecPromptBuilder precisa, mais o rastro (`omittedDocuments`,
 * `tags`, exemplos usados) que vira `meta` da mensagem.
 */
final class FlowspecContext
{
    /**
     * @param  Collection<int, Solution>  $solutions
     * @param  Collection<int, DocumentationPage>  $pages
     * @param  Collection<int, Integration>  $integrationDocs  integrações com `documentation` própria
     * @param  list<array{type: string, id: int, label: string}>  $omittedDocuments  cortados por orçamento — referência completa, não só o rótulo, para poderem virar sugestão de "adicionar" (ver FlowspecGenerationService::suggestedDocuments())
     * @param  Collection<int, FlowspecExample>  $examples
     * @param  list<string>  $tags  tags candidatas derivadas do pedido
     */
    public function __construct(
        public readonly Collection $solutions,
        public readonly Collection $pages,
        public readonly Collection $integrationDocs,
        public readonly array $omittedDocuments,
        public readonly Collection $examples,
        public readonly array $tags,
    ) {}

    /** Resumo auditável gravado em `flowspec_messages.meta`. */
    public function toMeta(): array
    {
        return [
            'solutions'         => $this->solutions->pluck('name')->all(),
            'pages'             => $this->pages->pluck('title')->all(),
            'integration_docs'  => $this->integrationDocs->pluck('name')->all(),
            'omitted_documents' => array_column($this->omittedDocuments, 'label'),
            'examples'          => $this->examples->pluck('slug')->all(),
            'tags'              => $this->tags,
        ];
    }
}
