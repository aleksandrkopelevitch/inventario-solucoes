<?php

namespace App\Actions\Flowspec;

use App\Enums\FlowspecAttachmentKind;
use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use Illuminate\Support\Collection;

/**
 * Attaches documentation that already lives in the inventory to a conversation.
 *
 * Only a REFERENCE is stored, never a copy: the page is read live when the
 * prompt is built, so editing documentation updates every conversation pointing
 * at it. That is also why this can't pre-compute a token estimate — see
 * FlowspecContextBudget, which measures these rows against their targets.
 *
 * Idempotent per reference: attaching the same page twice is a no-op rather
 * than a duplicate. The picker is a checkbox list and the assistant's own
 * "adicionar ao contexto" buttons point at the same references, so the same
 * page arriving twice is an ordinary thing to do, not a mistake worth an error.
 */
class AttachFlowspecDocuments
{
    /**
     * @param  list<array{type: string, id: int}>  $refs  `page` references from the picker
     * @return Collection<int, FlowspecAttachment> only the rows actually created
     */
    public function handle(FlowspecChat $chat, array $refs): Collection
    {
        $existing = $chat->attachments()
            ->where('kind', FlowspecAttachmentKind::Document)
            ->get(['reference_type', 'reference_id'])
            ->map(fn (FlowspecAttachment $a) => "{$a->reference_type}:{$a->reference_id}")
            ->all();

        $created = collect();

        foreach ($this->resolve($refs) as $model) {
            $key = $model->getMorphClass() . ':' . $model->getKey();

            if (in_array($key, $existing, true)) {
                continue;
            }

            $existing[] = $key;

            $created->push($chat->attachments()->create([
                'kind'           => FlowspecAttachmentKind::Document,
                'label'          => $this->label($model),
                'reference_type' => $model->getMorphClass(),
                'reference_id'   => $model->getKey(),
            ]));
        }

        return $created;
    }

    /**
     * Loads the referenced records, skipping anything with no documentation to
     * contribute — an empty page in the context list would read as material
     * that just isn't helping.
     *
     * @param  list<array{type: string, id: int}>  $refs
     * @return Collection<int, DocumentationPage>
     */
    private function resolve(array $refs): Collection
    {
        $pageIds = collect($refs)->where('type', 'page')->pluck('id')->all();

        return $pageIds === [] ? collect() : DocumentationPage::query()
            ->whereKey($pageIds)
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->with('notebook')
            ->get();
    }

    private function label(DocumentationPage $page): string
    {
        return "{$page->notebook?->name} — {$page->title}";
    }
}
