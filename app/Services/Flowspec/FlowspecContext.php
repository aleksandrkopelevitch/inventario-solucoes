<?php

namespace App\Services\Flowspec;

use App\Models\DocumentationPage;
use App\Models\FlowspecExample;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Support\Collection;

/**
 * Context material resolved for a flowSpec generation: the Solutions
 * considered, their documentation pages and the documentation of the
 * integrations they participate in (already trimmed to the budget — or,
 * when the request came with explicit `document_refs` from the chips picker,
 * exactly the documents chosen, with no scoring or trimming), the corpus
 * examples selected by tag, and the tags that drove the selection — everything
 * `FlowspecPromptBuilder` needs, plus the trail (`omittedDocuments`, `tags`,
 * examples used) that becomes the message's `meta`.
 */
final class FlowspecContext
{
    /**
     * @param  Collection<int, Solution>  $solutions
     * @param  Collection<int, DocumentationPage>  $pages
     * @param  Collection<int, Integration>  $integrationDocs  integrations with their own `documentation`
     * @param  list<array{type: string, id: int, label: string}>  $omittedDocuments  trimmed by budget — full reference, not just the label, so they can become an "add" suggestion (see FlowspecGenerationService::suggestedDocuments())
     * @param  Collection<int, FlowspecExample>  $examples
     * @param  list<string>  $tags  candidate tags derived from the request
     */
    public function __construct(
        public readonly Collection $solutions,
        public readonly Collection $pages,
        public readonly Collection $integrationDocs,
        public readonly array $omittedDocuments,
        public readonly Collection $examples,
        public readonly array $tags,
    ) {}

    /** Auditable summary recorded in `flowspec_messages.meta`. */
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
