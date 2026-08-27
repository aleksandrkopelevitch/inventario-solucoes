<?php

namespace App\Services\Flowspec;

use App\Enums\FlowspecAttachmentKind;
use App\Enums\FlowspecDocumentType;
use App\Enums\FlowspecTag;
use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\Solution;
use App\Support\Context\NativeAttachmentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;

/**
 * Turns a conversation's ATTACHED context into what a prompt needs, and
 * suggests what looks missing.
 *
 * The one rule that shapes this whole class: **nothing enters a prompt that
 * nobody attached.** An earlier version inferred Solutions by matching their
 * names in the request text and silently folded up to 60k characters of their
 * documentation into the request. That was invisible spend — the composer's
 * context meter could not show it before sending, so the number a user read and
 * the request they paid for were different numbers. The name matching survives,
 * but now it only produces SUGGESTIONS (`suggestFor()`), one click away from
 * becoming a real attachment the meter can see.
 *
 * No AI is involved in that matching and none is needed: it is a word-boundary
 * scan over the Solution catalog. It runs in PHP rather than SQL because
 * `Str::ascii()` folds "ó"->"o" portably between SQLite (dev) and PostgreSQL
 * (prod) without depending on an extension (`unaccent`) or a driver-specific
 * collation — at the catalog's scale (dozens of Solutions, one query) that
 * trade costs nothing.
 */
class FlowspecContextResolver
{
    public function resolve(FlowspecChat $chat, string $request): FlowspecContext
    {
        $attachments = $chat->attachments()->with('media')->get();

        $pages = $this->referencedDocumentation($attachments);
        [$textDocs, $nativeAttachments, $attachedMeta, $omittedAttachments] = $this->partitionMaterial($attachments);

        $tags = $this->candidateTags($this->normalize($request));

        return new FlowspecContext(
            pages: $pages,
            textDocs: $textDocs,
            referenceFlowspecs: $this->referenceFlowspecs($attachments),
            attachments: $nativeAttachments,
            attachedMeta: $attachedMeta,
            omittedAttachments: $omittedAttachments,
            examples: $this->selectExamples($tags),
            tags: $tags,
        );
    }

    /**
     * The documentation behind `document` attachments, read live from its
     * reference — never a copy, so an edited page is current in every
     * conversation pointing at it on the very next turn.
     *
     * @param  Collection<int, FlowspecAttachment>  $attachments
     * @return Collection<int, DocumentationPage>
     */
    private function referencedDocumentation(Collection $attachments): Collection
    {
        $pageIds = $attachments
            ->where('kind', FlowspecAttachmentKind::Document)
            ->where('reference_type', DocumentationPage::class)
            ->pluck('reference_id')
            ->all();

        return $pageIds === [] ? collect() : DocumentationPage::query()
            ->whereKey($pageIds)
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->with('notebook')
            ->get()
            ->sortBy(fn (DocumentationPage $page) => [$page->notebook_id, $page->position])
            ->values();
    }

    /**
     * Splits the material the user brought into text that gets inlined and
     * files the model reads natively (PDF/image) — the same partitioning
     * App\Services\Documentation\ContextDocumentResolver does for a caderno's
     * context documents.
     *
     * There is no character budget here, deliberately: the attach endpoints
     * already refused anything that wouldn't fit
     * (FlowspecContextBudget::attachableTokens), so whatever is stored is known
     * to fit and silently trimming it again would contradict the meter the user
     * read. Only the AGGREGATE byte ceiling on native attachments still applies
     * — that one is the provider's request limit, not our budget.
     *
     * @param  Collection<int, FlowspecAttachment>  $attachments
     * @return array{Collection<int, array{label: string, content: string}>, list<object>, list<array{id: int, name: string, kind: string}>, list<string>}
     */
    private function partitionMaterial(Collection $attachments): array
    {
        $maxBytes = (int) config('services.flowspec.max_attachment_bytes');

        $textDocs = collect();
        $native = [];
        $attachedMeta = [];
        $omitted = [];
        $bytes = 0;

        foreach ($attachments as $attachment) {
            if ($attachment->kind === FlowspecAttachmentKind::Document || $attachment->is_flowspec_reference) {
                continue; // handled by referencedDocumentation() / referenceFlowspecs()
            }

            if ($attachment->hasInlineText()) {
                $textDocs->push(['label' => $attachment->label, 'content' => (string) $attachment->content]);

                continue;
            }

            $media = $attachment->media;

            if ($media === null) {
                continue;
            }

            $mime = (string) $media->mime_type;

            // Only images and PDFs ride along natively, and this is the SAME
            // decision the ingest step priced the file with
            // (NativeAttachmentType) — a `Skipped` state alone isn't enough,
            // since SourceTextExtractor also returns it for a format it simply
            // can't read, which has nothing to send.
            $kind = NativeAttachmentType::for($mime, $media->extension);

            if ($kind === null) {
                continue;
            }

            if ($maxBytes > 0 && $bytes + (int) $media->size > $maxBytes) {
                $omitted[] = $attachment->label;

                continue;
            }

            $bytes += (int) $media->size;

            $native[] = $kind === NativeAttachmentType::IMAGE
                ? new LocalImage($media->getPath(), $mime)
                : new LocalDocument($media->getPath(), $mime);

            $attachedMeta[] = ['id' => $attachment->id, 'name' => $attachment->label, 'kind' => $kind];
        }

        return [$textDocs, $native, $attachedMeta, $omitted];
    }

    /**
     * Pasted `{meta, flowSpec}` documents, already minified at attach time.
     * They get their own prompt section instead of being mixed in with prose
     * material — see AttachFlowspecText for why this is a flag on a text
     * attachment rather than a third kind of attachment.
     *
     * @param  Collection<int, FlowspecAttachment>  $attachments
     * @return Collection<int, string>
     */
    private function referenceFlowspecs(Collection $attachments): Collection
    {
        return $attachments
            ->filter(fn (FlowspecAttachment $a) => $a->is_flowspec_reference && filled($a->content))
            ->map(fn (FlowspecAttachment $a) => (string) $a->content)
            ->values();
    }

    /**
     * Documentation worth attaching, for text that names systems by name —
     * whether that text is the message someone is typing or the assistant's own
     * "I'd need the IAM docs" reply. Each hit becomes an "adicionar ao contexto"
     * button; nothing is added until it's clicked.
     *
     * Never invents a name: it matches against the real Solution catalog, and
     * skips anything the conversation already has attached.
     *
     * @param  list<string>  $attachedKeys  `type:id` references already in the context
     * @return list<array{type: string, id: int, label: string}>
     */
    public function suggestFor(string $text, array $attachedKeys = []): array
    {
        $mentioned = $this->matchSolutions($this->normalize($text));

        if ($mentioned->isEmpty()) {
            return [];
        }

        // Only the columns used in the labels — never the longText
        // `documentation` (it's only used in the WHERE), since this runs on
        // every conversational reply and on composer keystrokes.
        // Through the caderno, not off the page: a page reaches a Solution via
        // `notebook.solutions` now, and the same page legitimately answers for
        // several of the mentioned systems at once.
        $pages = DocumentationPage::query()
            ->whereHas('notebook.solutions', fn ($q) => $q->whereKey($mentioned->modelKeys()))
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->with('notebook:id,name')
            ->orderBy('position')
            ->get(['id', 'notebook_id', 'title']);

        // collect($model->all()) before ->map(): mapping an empty
        // Eloquent\Collection doesn't downgrade it to a Support\Collection.
        return collect($pages->all())
            ->map(fn (DocumentationPage $page) => [
                'type'  => 'page',
                'id'    => $page->id,
                'label' => "{$page->notebook?->name} — {$page->title}",
            ])
            ->reject(fn (array $ref) => in_array($this->morphKey($ref), $attachedKeys, true))
            ->take((int) config('services.flowspec.max_suggested_documents'))
            ->values()
            ->all();
    }

    /**
     * The `type:id` keys a chat already has attached, in the shape suggestFor()
     * rejects against.
     *
     * @return list<string>
     */
    public function attachedKeys(FlowspecChat $chat): array
    {
        return $chat->attachments()
            ->where('kind', FlowspecAttachmentKind::Document)
            ->get(['reference_type', 'reference_id'])
            ->map(fn (FlowspecAttachment $a) => "{$a->reference_type}:{$a->reference_id}")
            ->all();
    }

    /**
     * Picker reference (`page:12`) -> stored morph key
     * (`App\Models\DocumentationPage:12`).
     *
     * @param  array{type: string, id: int}  $ref
     */
    private function morphKey(array $ref): string
    {
        return FlowspecDocumentType::from($ref['type'])->morphKey($ref['id']);
    }

    /**
     * Solutions whose name appears as a whole word in the text.
     *
     * @return Collection<int, Solution>
     */
    private function matchSolutions(string $normalizedText): Collection
    {
        return Solution::query()
            ->get(['id', 'name'])
            ->filter(function (Solution $solution) use ($normalizedText) {
                $name = $this->normalize($solution->name);

                return $name !== ''
                    && preg_match('/(?<![a-z0-9])' . preg_quote($name, '/') . '(?![a-z0-9])/', $normalizedText) === 1;
            })
            ->values();
    }

    /** @return list<string> */
    private function candidateTags(string $normalizedRequest): array
    {
        $tags = [];

        foreach (FlowspecTag::cases() as $tag) {
            foreach ($tag->keywords() as $keyword) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($keyword, '/') . '(?![a-z0-9])/', $normalizedRequest) === 1) {
                    $tags[] = $tag->value;

                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * The 2-3 examples with the most tags in common with the request — more
     * than that dilutes the signal and wastes tokens. Fallback: the generic
     * anchor example.
     *
     * @param  list<string>  $tags
     * @return Collection<int, FlowspecExample>
     */
    private function selectExamples(array $tags): Collection
    {
        $limit = (int) config('services.flowspec.max_examples');

        $examples = $tags === []
            ? collect()
            : FlowspecExample::query()->active()->withAnyTag($tags)->get()
                ->sortByDesc(fn (FlowspecExample $example) => count(array_intersect($example->tags, $tags)))
                ->take($limit)
                ->values();

        if ($examples->isEmpty()) {
            $examples = FlowspecExample::query()
                ->active()
                ->where('slug', config('services.flowspec.fallback_example'))
                ->get();
        }

        return $examples;
    }

    /** Lowercase and unaccented, to match keyword and Solution name. */
    private function normalize(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }
}
