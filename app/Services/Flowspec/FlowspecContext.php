<?php

namespace App\Services\Flowspec;

use App\Models\DocumentationPage;
use App\Models\FlowspecExample;
use Illuminate\Support\Collection;

/**
 * The material one generation actually runs on — all of it attached to the
 * conversation by hand, none of it inferred (see FlowspecContextResolver).
 *
 * `pages` is inventory documentation read live from its references (there used
 * to be a second collection beside it, an integration's own single-page
 * documentation, which no longer exists as a kind of documentation at all);
 * `textDocs` and `attachments` are the material the user brought,
 * split into what gets inlined and what the model reads natively;
 * `referenceFlowspecs` are pasted pipelines, which earn their own prompt
 * section. `omittedAttachments` and `tags` are the audit trail that becomes the
 * message's `meta`.
 */
final class FlowspecContext
{
    /**
     * @param  Collection<int, DocumentationPage>  $pages
     * @param  Collection<int, array{label: string, content: string}>  $textDocs  uploads read as text + pasted text
     * @param  Collection<int, array{label: string, content: string}>  $referenceFlowspecs  pasted `{meta, flowSpec}` documents, already minified, under the name the user gave each
     * @param  list<object>  $attachments  Laravel\Ai LocalImage/LocalDocument handed to the model
     * @param  list<array{id: int, name: string, kind: string}>  $attachedMeta
     * @param  list<string>  $omittedAttachments  dropped by the aggregate byte ceiling, never silently
     * @param  Collection<int, FlowspecExample>  $examples
     * @param  list<string>  $tags  candidate tags derived from the request
     */
    public function __construct(
        public readonly Collection $pages,
        public readonly Collection $textDocs,
        public readonly Collection $referenceFlowspecs,
        public readonly array $attachments,
        public readonly array $attachedMeta,
        public readonly array $omittedAttachments,
        public readonly Collection $examples,
        public readonly array $tags,
    ) {}

    /** Documentation from the inventory that this request is standing on. */
    public function hasDocumentation(): bool
    {
        return $this->pages->isNotEmpty();
    }

    /** Auditable summary recorded in `flowspec_messages.meta`. */
    public function toMeta(): array
    {
        return [
            'pages'               => $this->pages->pluck('title')->all(),
            'text_docs'           => $this->textDocs->pluck('label')->all(),
            'reference_flowspecs' => $this->referenceFlowspecs->count(),
            'attached_files'      => array_column($this->attachedMeta, 'name'),
            'omitted_attachments' => $this->omittedAttachments,
            'examples'            => $this->examples->pluck('slug')->all(),
            'tags'                => $this->tags,
        ];
    }
}
